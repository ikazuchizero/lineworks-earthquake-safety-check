// 取得した地震情報から通知対象を選別するファイル

function selectNotificationTargets(events, state, config, now, context) {
    const nowDate = now instanceof Date ? now : new Date();
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

        // 長期間停止後の再開や初回導入時に、古い地震を通知しない
        if (isStaleEarthquakeTime(candidate.earthquakeTime, nowDate, config)) {
            logRoutine(
                context,
                candidate,
                LOG_MESSAGES.stale,
                "max_age_hours=" + config.maxNotificationAgeHours
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
                        nowDate
                    );
                    markStateChanged(state);
                } else if (isBetterQuietCandidate(candidate, pending)) {
                    const merged = pendingUnknownRecord(candidate, nowDate);
                    merged.firstSeenAt = pending.firstSeenAt || merged.firstSeenAt;
                    state.pendingUnknown[candidate.earthquakeTime] = merged;
                    markStateChanged(state);
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

    // 前回の実行で解放したが、送信結果が確定していない静穏通知を再試行する
    if (state.quietRelease) {
        const release = state.quietRelease;
        const released = release.candidate;

        if (!released || !released.earthquakeTime) {
            state.quietRelease = null;
            markStateChanged(state);
        } else if (
            state.notified[released.dedupeKey] ||
            state.notifiedByTime[released.earthquakeTime]
        ) {
            state.quietRelease = null;
            markStateChanged(state);
        } else if (isStaleEarthquakeTime(released.earthquakeTime, nowDate, config)) {
            logRoutine(
                context,
                released,
                LOG_MESSAGES.stale,
                "quiet_release"
            );
            state.quietCompletedThrough = release.windowEnd || state.quietCompletedThrough;
            state.quietRelease = null;
            markStateChanged(state);
        } else if (Number(released.maxScale || 0) < config.notifyScale) {
            logRoutine(
                context,
                released,
                LOG_MESSAGES.skipScale,
                "quiet_release_below_scale"
            );
            state.quietCompletedThrough = release.windowEnd || state.quietCompletedThrough;
            state.quietRelease = null;
            markStateChanged(state);
        } else {
            const pending =
                released.hypocenterName === "UNKNOWN"
                    ? state.pendingUnknown[released.earthquakeTime]
                    : null;
            const holdUnknown =
                pending && !isPendingUnknownExpired(pending, nowDate, config);

            if (!holdUnknown) {
                const candidate = Object.assign({}, released, {
                    quietWindowStart: release.windowStart,
                    quietWindowEnd: release.windowEnd,
                });

                quietTarget = candidate;
                seenDedupeKeys[candidate.dedupeKey] = true;
                seenEarthquakeTimes[candidate.earthquakeTime] = true;
                if (
                    removePendingUnknownsForQuietWindow(
                        release.windowStart,
                        state,
                        config,
                        candidate.earthquakeTime
                    )
                ) {
                    markStateChanged(state);
                }
            }
        }
    }

    // 静穏時間帯が終了していれば、保留していた1件を解放待ちへ移す
    if (!quietTarget && batch && quietBatchHasEnded(batch, nowDate)) {
        const completedThrough = state.quietCompletedThrough;

        if (completedThrough && compareTimes(batch.windowEnd, completedThrough) <= 0) {
            state.quietBatch = null;
            markStateChanged(state);
        } else {
            let quietCandidate = candidateFromQuietBatch(batch);

            if (!quietCandidate) {
                state.quietBatch = null;
                markStateChanged(state);
            } else {
                quietCandidate = Object.assign({}, quietCandidate);

                // 同じ時間帯の最新候補のほうが良ければ、そちらを採用する
                for (const entry of quietCandidates) {
                    if (
                        String(entry.window.windowStart) === String(batch.windowStart) &&
                        isBetterQuietCandidate(entry.candidate, quietCandidate)
                    ) {
                        quietCandidate = Object.assign({}, entry.candidate);
                    }
                }

                if (
                    quietCandidate &&
                    isStaleEarthquakeTime(quietCandidate.earthquakeTime, nowDate, config)
                ) {
                    logRoutine(
                        context,
                        quietCandidate,
                        LOG_MESSAGES.stale,
                        "quiet_batch"
                    );
                    state.quietCompletedThrough = batch.windowEnd;
                    state.quietBatch = null;
                    markStateChanged(state);
                    quietCandidate = null;
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
                    markStateChanged(state);
                    quietCandidate = null;
                }

                if (quietCandidate) {
                    quietCandidate.quietWindowStart = batch.windowStart;
                    quietCandidate.quietWindowEnd = batch.windowEnd;

                    if (
                        state.notified[quietCandidate.dedupeKey] ||
                        state.notifiedByTime[quietCandidate.earthquakeTime]
                    ) {
                        state.quietCompletedThrough = batch.windowEnd;
                        state.quietBatch = null;
                        markStateChanged(state);
                    } else {
                        // 送信が失敗しても再試行できるよう、解放待ちとして保存する。
                        // quietBatch を空けることで、次の時間帯を取り込める。
                        state.quietRelease = {
                            windowStart: batch.windowStart,
                            windowEnd: batch.windowEnd,
                            candidate: quietCandidate,
                        };
                        state.quietBatch = null;
                        markStateChanged(state);

                        const pending =
                            quietCandidate.hypocenterName === "UNKNOWN"
                                ? state.pendingUnknown[quietCandidate.earthquakeTime]
                                : null;
                        const holdUnknown =
                            pending &&
                            !isPendingUnknownExpired(pending, nowDate, config);

                        // UNKNOWNの続報待ちの間は通知しない。
                        // 解放待ちとして保持し、次の時間帯の取り込みは妨げない。
                        if (!holdUnknown) {
                            quietTarget = quietCandidate;
                            seenDedupeKeys[quietCandidate.dedupeKey] = true;
                            seenEarthquakeTimes[quietCandidate.earthquakeTime] = true;

                            if (
                                removePendingUnknownsForQuietWindow(
                                    batch.windowStart,
                                    state,
                                    config,
                                    quietCandidate.earthquakeTime
                                )
                            ) {
                                markStateChanged(state);
                            }
                        }
                    }
                }
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

        if (
            best &&
            !quietTarget &&
            state.quietRelease &&
            String(state.quietRelease.windowStart) === String(latestWindow.windowStart)
        ) {
            // 解放待ちの時間帯に良い続報が来たら、送信前に候補を更新する
            if (isBetterQuietCandidate(best, state.quietRelease.candidate)) {
                state.quietRelease = Object.assign({}, state.quietRelease, {
                    candidate: best,
                });
                markStateChanged(state);
                logRoutine(
                    context,
                    best,
                    LOG_MESSAGES.quietCaptured,
                    "window=" + latestWindow.windowStart + " release_replaced"
                );
            }
        } else if (best && captureQuietCandidate(best, latestWindow, state, config)) {
            markStateChanged(state);
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
                nowDate
            );
            markStateChanged(state);
            logRoutine(
                context,
                unknownCandidate,
                LOG_MESSAGES.unknownPending,
                "pending_created"
            );
            continue;
        }

        if (isPendingUnknownExpired(pending, nowDate, config)) {
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
            markStateChanged(state);
            continue;
        }

        if (
            seenEarthquakeTimes[earthquakeTime] ||
            !isPendingUnknownExpired(pending, nowDate, config)
        ) {
            continue;
        }

        if (isStaleEarthquakeTime(earthquakeTime, nowDate, config)) {
            logRoutine(
                context,
                candidateFromPendingUnknown(earthquakeTime, pending) || {
                    earthquakeTime: earthquakeTime,
                    hypocenterName: "UNKNOWN",
                    maxScale: 0,
                    dedupeKey: earthquakeTime + "|UNKNOWN",
                },
                LOG_MESSAGES.stale,
                "pending_unknown"
            );
            delete state.pendingUnknown[earthquakeTime];
            markStateChanged(state);
            continue;
        }

        if (pendingUnknownHasUnfinishedQuietBatch(earthquakeTime, state, config)) {
            continue;
        }

        // 静穏時間帯がまだ終わっていない保留分は通知しない
        // （同じ時間帯で選ばれなかったUNKNOWNが夜間に漏れるのを防ぐ）
        const pendingWindow = quietWindowFor(earthquakeTime, config);
        const pendingWindowEnd = pendingWindow
            ? parseTokyoTime(pendingWindow.windowEnd)
            : null;

        if (pendingWindowEnd && pendingWindowEnd.getTime() > nowDate.getTime()) {
            continue;
        }

        const candidate = candidateFromPendingUnknown(earthquakeTime, pending);

        if (!candidate) {
            console.log("保留中のUNKNOWNが不正なため削除: " + earthquakeTime);
            delete state.pendingUnknown[earthquakeTime];
            markStateChanged(state);
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
            markStateChanged(state);
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
