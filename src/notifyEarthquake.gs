// LINE WORKSへの通知実行を管理するファイル

function notifyEarthquake(context, event) {
    const eventId = event.id;
    const maxScale = event.earthquake.maxScale || 0;
    const scaleText = SCALE_NAMES[maxScale] || "不明";

    const message = createEarthquakeMessage(event, scaleText, context.formUrl);

    const accessToken = getAccessToken(context.properties);

    sendLineWorksMessage(accessToken, context.properties, context.roomId, message);

    context.properties.setProperty("LAST_NOTIFIED_ID", eventId);

    logResult(context, eventId, scaleText, LOG_MESSAGES.success);
}
