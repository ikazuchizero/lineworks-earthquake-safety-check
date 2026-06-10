// スプレッドシートへの通常ログを管理するファイル

// 実行結果を必要に応じてログシートへ記録する。
function logResult(context, eventId, scaleText, result) {
    if (shouldSkipLog(context, result)) {
        return;
    }

    writeLog(context.sheet, context.nowTime, eventId, scaleText, result);
}

// 通知対象外や重複通知のログを一定間隔に間引くか判定する。
function shouldSkipLog(context, result) {
    if (result !== LOG_MESSAGES.skipScale && result !== LOG_MESSAGES.duplicate) {
        return false;
    }

    const key = "LAST_ROUTINE_LOG_TIME";
    const lastTime = Number(context.properties.getProperty(key) || 0);
    const oneHour = 60 * 60 * 1000;

    if (Date.now() - lastTime < oneHour) {
        return true;
    }

    context.properties.setProperty(key, String(Date.now()));

    return false;
}

// 指定されたログシートへ1行追記する。
function writeLog(sheet, time, eventId, scale, result) {
    if (!sheet) {
        return;
    }

    sheet.appendRow([time, eventId, scale, result]);
}

// 例外内容をログシートへ記録する。
function processError(context, error) {
    writeLog(context.sheet, context.nowTime, "-", "-", "エラー: " + error.message);
}
