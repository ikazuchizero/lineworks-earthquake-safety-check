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
    saveState(context, state);

    try {
        const accessToken = getAccessToken(context.properties);
        sendLineWorksMessage(accessToken, context.properties, context.config.roomId, message);
    } catch (e) {
        // 送信失敗が確定した場合はマークを外す（次回は試行回数1から）
        delete state.inflight[target.dedupeKey];

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
        state.quietCompletedThrough = target.quietWindowEnd;
        state.quietBatch = null;
    }

    saveState(context, state);
    logResult(context, target, LOG_MESSAGES.success, "回答者URL発行済み attempt=" + attempt);
}
