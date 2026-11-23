// スプレッドシートへログを書き込むファイル

const MAX_LOG_DETAIL_LENGTH = 5000;

function writeLog(context, entry) {
    if (!context || !context.logSheet) {
        return;
    }

    const sheet = context.logSheet;
    const row = [
        entry.time instanceof Date ? entry.time : new Date(),
        entry.eventId || "-",
        entry.earthquakeTime ? formatEarthquakeTime(entry.earthquakeTime) : "-",
        entry.hypocenterName || "-",
        entry.scaleText ||
            (entry.maxScale !== undefined ? scaleText(entry.maxScale) : "-"),
        entry.result || "-",
        truncateText(entry.detail || "", MAX_LOG_DETAIL_LENGTH),
    ];

    try {
        sheet.appendRow(row);
    } catch (e) {
        // ログ記録の失敗で通知処理を止めない
        console.error("ログの記録に失敗しました: " + e.message);
    }
}

function logResult(context, target, result, detail) {
    writeLog(context, {
        time: new Date(),
        eventId: target.eventId || "-",
        earthquakeTime: target.earthquakeTime,
        hypocenterName: target.hypocenterName,
        maxScale: target.maxScale,
        result: result,
        detail: detail || "",
    });
}

// 通知対象外・重複などの通常系ログは1時間に1回だけ書く
function logRoutine(context, target, result, detail) {
    if (!shouldLogRoutine(context)) {
        return;
    }

    logResult(context, target, result, detail);
}

function shouldLogRoutine(context) {
    const key = PROPERTY_KEYS.lastRoutineLogTime;
    const lastTime = Number(context.properties.getProperty(key) || 0);
    const now = Date.now();
    const oneHour = 60 * 60 * 1000;

    if (now - lastTime < oneHour) {
        return false;
    }

    context.properties.setProperty(key, String(now));

    return true;
}
