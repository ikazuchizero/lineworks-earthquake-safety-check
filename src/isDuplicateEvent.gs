// 既に通知済みの地震か判定するファイル

function isDuplicateEvent(properties, eventId) {
    const lastNotifiedId = properties.getProperty("LAST_NOTIFIED_ID");

    return eventId === lastNotifiedId;
}
