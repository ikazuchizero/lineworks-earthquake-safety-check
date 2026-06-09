// 通常ログを記録するための共通処理ファイル
function logResult(context, eventId, scaleText, result) {
    if (shouldSkipLog(context, result)) {
        return;
    }

    writeLog(context.sheet, context.nowTime, eventId, scaleText, result);
}

//通知対象外・重複パスは毎回書かない
//送信成功・エラーだけ必ず書く
//通知対象外は1時間に1回だけ書く
function shouldSkipLog(context, result) {
    if (result !== LOG_MESSAGES.skipScale && result !== LOG_MESSAGES.duplicate) {
        return false;
    }

    const key = "LAST_ROUTINE_LOG_TIME";

    const lastTime = Number(context.properties.getProperty(key) || 0);

    const nowTime = Date.now();

    const oneHour = 60 * 60 * 1000;

    if (nowTime - lastTime < oneHour) {
        return true;
    }

    context.properties.setProperty(key, String(nowTime));

    return false;
}
