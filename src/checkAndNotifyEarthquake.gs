// メイン処理を管理するファイル

// 地震情報の取得から通知、実行状態記録までを実行する。
function checkAndNotifyEarthquake() {
    const lock = LockService.getScriptLock();

    if (!lock.tryLock(1000)) {
        return;
    }

    const context = getExecutionContext();

    try {
        const event = getLatestEarthquake();

        if (!processEarthquakeValidation(context, event)) {
            recordExecutionSuccess();
            return;
        }

        notifyEarthquake(context, event);

        recordExecutionSuccess();
    } catch (e) {
        processError(context, e);
        recordExecutionFailure(e);
        throw e;
    } finally {
        lock.releaseLock();
    }
}

// 実行時に利用するプロパティ、ログシート、通知先情報を取得する。
function getExecutionContext() {
    const properties = PropertiesService.getScriptProperties();
    const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(LOG_SHEET_NAME);

    return {
        properties,
        formUrl: properties.getProperty("FORM_URL"),
        roomId: properties.getProperty("ROOM_ID"),
        sheet,
        nowTime: new Date(),
    };
}

// P2PQuake APIから最新の地震情報を1件取得する。
function getLatestEarthquake() {
    const response = UrlFetchApp.fetch(P2PQUAKE_API_URL, {
        muteHttpExceptions: true,
    });

    const code = response.getResponseCode();
    const text = response.getContentText();

    if (code !== 200) {
        throw createHttpError(ERROR_MESSAGES.earthquakeApiFailed, code, text);
    }

    const events = JSON.parse(text);

    if (!events || events.length === 0) {
        return null;
    }

    return events[0];
}

// 取得した地震情報が通知対象かどうかを判定する。
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

// 指定した地震IDが直近の通知済みIDと一致するか判定する。
function isDuplicateEvent(properties, eventId) {
    return eventId === properties.getProperty("LAST_NOTIFIED_ID");
}

// 安否確認メッセージを作成し、LINE WORKSへ通知する。
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

// 地震情報とフォームURLから安否確認メッセージ本文を作成する。
function createEarthquakeMessage(event, scaleText, formUrl) {
    const hypocenter = event.earthquake.hypocenter?.name || "不明";
    const eventTime = new Date(event.earthquake.time);
    const formattedTime = Utilities.formatDate(eventTime, "Asia/Tokyo", "yyyy/MM/dd HH:mm:ss");

    return `お疲れ様です。

先ほど発生した地震について安否確認を実施いたします。

【地震情報】
・発生時刻：${formattedTime}
・震源地：${hypocenter}
・最大震度：${scaleText}

以下のフォームより回答をお願いいたします。

${formUrl}

余震の可能性もありますので、引き続き安全確保をお願いいたします。`;
}

// テスト用に最後に通知した地震IDを削除する。
function resetLastNotifiedId() {
    PropertiesService.getScriptProperties().deleteProperty("LAST_NOTIFIED_ID");
}
