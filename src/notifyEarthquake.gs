// LINE WORKSへの通知実行と状態更新を管理するファイル

function notifyEarthquake(context, state, target) {
    const formUrl = resolveFormForNotification(context, state, target);
    const message = createEarthquakeMessage(target, formUrl);

    const accessToken = getAccessToken(context.properties);
    sendLineWorksMessage(accessToken, context.properties, context.config.roomId, message);

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

    if (target.quietWindowEnd) {
        state.quietCompletedThrough = target.quietWindowEnd;
        state.quietBatch = null;
    }

    saveState(context, state);
    logResult(context, target, LOG_MESSAGES.success, "回答者URL発行済み");
}
