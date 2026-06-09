// 地震情報が通知対象か判定するファイル

function processEarthquakeValidation(context, event) {
    if (!event) {
        logResult(context, "-", "-", LOG_MESSAGES.noEvent);
        return false;
    }

    if (!event.earthquake) {
        logResult(context, event.id || "-", "-", LOG_MESSAGES.noEarthquakeData);
        return false;
    }

    const eventId = event.id;

    if (!eventId) {
        throw new Error(ERROR_MESSAGES.earthquakeIdMissing);
    }

    const maxScale = event.earthquake.maxScale || 0;
    const scaleText = SCALE_NAMES[maxScale] || "不明";

    if (maxScale < NOTIFY_SCALE) {
        logResult(context, eventId, scaleText, LOG_MESSAGES.skipScale);
        return false;
    }

    if (isDuplicateEvent(context.properties, eventId)) {
        logResult(context, eventId, scaleText, LOG_MESSAGES.duplicate);
        return false;
    }

    return true;
}
