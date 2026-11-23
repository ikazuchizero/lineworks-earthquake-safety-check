// LINE WORKSへの通知実行と状態更新を管理するファイル

function notifyEarthquake(context, state, target) {
    const formUrl = resolveFormForNotification(context, state, target);
    const message = createEarthquakeMessage(target, formUrl);
    const previous = state.inflight[target.dedupeKey];
    const attempt = previous && previous.attempts ? Number(previous.attempts) + 1 : 1;

    if (previous) {
        console.log(
            LOG_MESSAGES.inflightRetry +
                ": " +
                target.dedupeKey +
                " attempts=" +
                attempt
        );
    }

    // 送信前に「送信中」を保存する。送信後に状態保存が失敗しても、
    // 次回このマークを見て「結果不明の再送」として扱えるようにする。
    state.inflight[target.dedupeKey] = {
        dedupeKey: target.dedupeKey,
        earthquakeTime: target.earthquakeTime,
        hypocenterName: target.hypocenterName,
        maxScale: target.maxScale,
        formUrl: formUrl,
        attempts: attempt,
        startedAt: new Date().toISOString(),
    };
    markStateChanged(state);
    saveState(context, state);

    try {
        const accessToken = getAccessToken(context.properties, context.config);
        sendLineWorksMessage(accessToken, context.properties, context.config.roomId, message);
    } catch (e) {
        // 送信失敗が確定した場合はマークを外す（次回は試行回数1から）
        delete state.inflight[target.dedupeKey];
        markStateChanged(state);

        try {
            saveState(context, state);
        } catch (saveError) {
            console.error("送信中マークの解除に失敗しました: " + saveError.message);
        }

        throw e;
    }

    const notifiedAt = new Date().toISOString();
    const record = {
        dedupeKey: target.dedupeKey,
        eventId: target.eventId || null,
        earthquakeTime: target.earthquakeTime,
        hypocenterName: target.hypocenterName,
        maxScale: target.maxScale,
        notifiedAt: notifiedAt,
    };

    state.notified[target.dedupeKey] = record;
    state.notifiedByTime[target.earthquakeTime] = {
        dedupeKey: target.dedupeKey,
        notifiedAt: notifiedAt,
    };
    delete state.pendingUnknown[target.earthquakeTime];
    delete state.inflight[target.dedupeKey];

    if (target.quietWindowEnd) {
        const currentCompleted = state.quietCompletedThrough;

        if (
            !currentCompleted ||
            compareTimes(target.quietWindowEnd, currentCompleted) > 0
        ) {
            state.quietCompletedThrough = target.quietWindowEnd;
        }

        // 解放待ちだった静穏通知が成功した場合だけクリアする。
        // 次の時間帯のバッチは残す。
        if (
            state.quietRelease &&
            quietWindowMatches(
                state.quietRelease,
                target.quietWindowStart,
                target.quietWindowEnd
            )
        ) {
            state.quietRelease = null;
        }

        if (
            state.quietBatch &&
            quietWindowMatches(
                state.quietBatch,
                target.quietWindowStart,
                target.quietWindowEnd
            )
        ) {
            state.quietBatch = null;
        }

        markStateChanged(state);
    }

    markStateChanged(state);
    saveState(context, state);
    logResult(context, target, LOG_MESSAGES.success, "回答者URL発行済み attempt=" + attempt);
}

function quietWindowMatches(batch, windowStart, windowEnd) {
    if (!batch) {
        return false;
    }

    if (windowStart && String(batch.windowStart) === String(windowStart)) {
        return true;
    }

    return !!windowEnd && String(batch.windowEnd) === String(windowEnd);
}
