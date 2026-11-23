// 取得した地震情報から通知対象を選別するファイル

function selectNotificationTargets(events, state, config, now, context) {
    const candidatesByTime = {};
    const quietCandidates = [];
    let quietTarget = null;

    for (const event of events) {
        const built = buildEarthquakeCandidate(event);

        if (!built.candidate) {
            console.log(
                "地震イベントをスキップ: " +
                    built.reason +
                    " event_id=" +
                    (built.eventId || "-")
            );
            continue;
        }

        const candidate = built.candidate;

        if (candidate.maxScale < config.notifyScale) {
            logRoutine(
                context,
                candidate,
                LOG_MESSAGES.skipScale,
                "maxScale=" + candidate.maxScale
            );
            continue;
        }

        if (state.notified[candidate.dedupeKey]) {
            logRoutine(
                context,
                candidate,
                LOG_MESSAGES.duplicate,
                "dedupe_key=" + candidate.dedupeKey
            );
            continue;
        }

        if (state.notifiedByTime[candidate.earthquakeTime]) {
            logRoutine(
                context,
                candidate,
                LOG_MESSAGES.duplicate,
                "earthquake_time=" + candidate.earthquakeTime
            );
            continue;
        }

        const quietWindow = quietWindowFor(candidate.earthquakeTime, config);

        if (quietWindow) {
            if (candidate.hypocenterName === "UNKNOWN") {
                const pending = state.pendingUnknown[candidate.earthquakeTime];

                if (!pending) {
                    state.pendingUnknown[candidate.earthquakeTime] = pendingUnknownRecord(
                        candidate,
                        now
                    );
                } else if (isBetterQuietCandidate(candidate, pending)) {
                    const merged = pendingUnknownRecord(candidate, now);
                    merged.firstSeenAt = pending.firstSeenAt || merged.firstSeenAt;
                    state.pendingUnknown[candidate.earthquakeTime] = merged;
                }
            }

            quietCandidates.push({
                candidate: candidate,
                window: quietWindow,
            });
            continue;
        }

        if (!candidatesByTime[candidate.earthquakeTime]) {
            candidatesByTime[candidate.earthquakeTime] = [];
        }

        candidatesByTime[candidate.earthquakeTime].push(candidate);
    }

    const targets = [];
    const seenDedupeKeys = {};
    const seenEarthquakeTimes = {};
    const batch = state.quietBatch;

    // 静穏時間帯が終了していれば、保留していた1件を通知候補へ移す
    // （新しい時間帯を取り込む前に、前の時間帯を解放しておく）
    if (batch && quietBatchHasEnded(batch, now)) {
        const completedThrough = state.quietCompletedThrough;

        if (!completedThrough || compareTimes(batch.windowEnd, completedThrough) > 0) {
            let quietCandidate = candidateFromQuietBatch(batch);

            if (quietCandidate) {
                quietCandidate = Object.assign({}, quietCandidate);
            }

            if (quietCandidate && quietCandidate.hypocenterName === "UNKNOWN") {
                const pending = state.pendingUnknown[quietCandidate.earthquakeTime];

                // 保留レコードが消えている場合は待ち続けず、静穏バッチを解放する
                if (pending && !isPendingUnknownExpired(pending, now, config)) {
                    quietCandidate = null;
                }
            }

            // しきい値が引き上げられた等で対象外になった場合は通知せず破棄する
            if (
                quietCandidate &&
                Number(quietCandidate.maxScale || 0) < config.notifyScale
            ) {
                logRoutine(
                    context,
                    quietCandidate,
                    LOG_MESSAGES.skipScale,
                    "quiet_below_scale"
                );
                state.quietCompletedThrough = batch.windowEnd;
                state.quietBatch = null;
                quietCandidate = null;
            }

            if (
                quietCandidate &&
                !state.notified[quietCandidate.dedupeKey] &&
                !state.notifiedByTime[quietCandidate.earthquakeTime]
            ) {
                quietCandidate.quietWindowEnd = batch.windowEnd;
                quietTarget = quietCandidate;
                seenDedupeKeys[quietCandidate.dedupeKey] = true;
                seenEarthquakeTimes[quietCandidate.earthquakeTime] = true;
                removePendingUnknownsForQuietWindow(
                    batch.windowStart,
                    state,
                    config,
                    quietCandidate.earthquakeTime
                );
            }
        }
    }

    // 静穏時間帯の候補は、最新の時間帯から1件だけ採用する
    if (quietCandidates.length > 0) {
        quietCandidates.sort(function (left, right) {
            return compareTimes(right.window.windowStart, left.window.windowStart);
        });

        const latestWindow = quietCandidates[0].window;
        let best = null;

        for (const entry of quietCandidates) {
            if (String(entry.window.windowStart) !== String(latestWindow.windowStart)) {
                break;
            }

            if (!best || isBetterQuietCandidate(entry.candidate, best)) {
                best = entry.candidate;
            }
        }

        if (best && captureQuietCandidate(best, latestWindow, state, config)) {
            logRoutine(
                context,
                best,
                LOG_MESSAGES.quietCaptured,
                "window=" + latestWindow.windowStart
            );
        }
    }

    // 通常時間帯の候補は発生時刻ごとに1件へ絞る
    for (const earthquakeTime of Object.keys(candidatesByTime)) {
        const list = candidatesByTime[earthquakeTime];
        const named = list.filter(function (candidate) {
            return candidate.hypocenterName !== "UNKNOWN";
        });

        if (named.length > 0) {
            addTargetIfNotSeen(targets, seenDedupeKeys, seenEarthquakeTimes, named[0]);
            continue;
        }

        const unknownCandidate = list[0];
        const pending = state.pendingUnknown[earthquakeTime];

        if (!pending) {
            state.pendingUnknown[earthquakeTime] = pendingUnknownRecord(
                unknownCandidate,
                now
            );
            logRoutine(
                context,
                unknownCandidate,
                LOG_MESSAGES.unknownPending,
                "pending_created"
            );
            continue;
        }

        if (isPendingUnknownExpired(pending, now, config)) {
            logRoutine(
                context,
                unknownCandidate,
                LOG_MESSAGES.unknownExpired,
                "pending_expired"
            );
            addTargetIfNotSeen(
                targets,
                seenDedupeKeys,
                seenEarthquakeTimes,
                unknownCandidate
            );
        }
    }

    // APIの取得結果に含まれなくなった保留分も、期限切れなら通知する
    for (const earthquakeTime of Object.keys(state.pendingUnknown)) {
        const pending = state.pendingUnknown[earthquakeTime];

        if (state.notifiedByTime[earthquakeTime]) {
            delete state.pendingUnknown[earthquakeTime];
            continue;
        }

        if (
            seenEarthquakeTimes[earthquakeTime] ||
            !isPendingUnknownExpired(pending, now, config)
        ) {
            continue;
        }

        if (pendingUnknownHasUnfinishedQuietBatch(earthquakeTime, state, now)) {
            continue;
        }

        // 静穏時間帯がまだ終わっていない保留分は通知しない
        // （同じ時間帯で選ばれなかったUNKNOWNが夜間に漏れるのを防ぐ）
        const pendingWindow = quietWindowFor(earthquakeTime, config);
        const pendingWindowEnd = pendingWindow
            ? parseTokyoTime(pendingWindow.windowEnd)
            : null;

        if (pendingWindowEnd && pendingWindowEnd.getTime() > now.getTime()) {
            continue;
        }

        const candidate = candidateFromPendingUnknown(earthquakeTime, pending);

        if (!candidate) {
            console.log("保留中のUNKNOWNが不正なため削除: " + earthquakeTime);
            delete state.pendingUnknown[earthquakeTime];
            continue;
        }

        if (candidate.maxScale < config.notifyScale) {
            logRoutine(
                context,
                candidate,
                LOG_MESSAGES.skipScale,
                "pending_unknown_below_scale"
            );
            delete state.pendingUnknown[earthquakeTime];
            continue;
        }

        logRoutine(
            context,
            candidate,
            LOG_MESSAGES.unknownExpired,
            "pending_expired_without_current_event"
        );
        addTargetIfNotSeen(targets, seenDedupeKeys, seenEarthquakeTimes, candidate);
    }

    if (quietTarget) {
        targets.unshift(quietTarget);
    }

    return targets;
}

function candidateFromQuietBatch(batch) {
    if (!batch || !batch.candidate) {
        return null;
    }

    const candidate = batch.candidate;

    return candidate.earthquakeTime ? candidate : null;
}

function addTargetIfNotSeen(targets, seenDedupeKeys, seenEarthquakeTimes, candidate) {
    if (
        seenDedupeKeys[candidate.dedupeKey] ||
        seenEarthquakeTimes[candidate.earthquakeTime]
    ) {
        return;
    }

    seenDedupeKeys[candidate.dedupeKey] = true;
    seenEarthquakeTimes[candidate.earthquakeTime] = true;
    targets.push(candidate);
}
