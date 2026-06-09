// 1時間ごとの正常動作率をヘルスチェックシートへ記録するファイル

function writeHourlyHealthCheck() {
    const properties = PropertiesService.getScriptProperties();

    const spreadsheet = SpreadsheetApp.getActiveSpreadsheet();

    const sheet = getOrCreateHealthSheet(spreadsheet);

    const nowTime = new Date();

    const periodStartTime = Number(properties.getProperty(HEALTH_KEYS.periodStartTime) || Date.now());

    const successCount = Number(properties.getProperty(HEALTH_KEYS.successCount) || 0);

    const failureCount = Number(properties.getProperty(HEALTH_KEYS.failureCount) || 0);

    const expectedCount = 60;

    const totalCount = successCount + failureCount;

    const successRate = expectedCount === 0 ? 0 : successCount / expectedCount;

    const lastSuccessTime = formatHealthTime(properties.getProperty(HEALTH_KEYS.lastSuccessTime));

    const lastErrorTime = formatHealthTime(properties.getProperty(HEALTH_KEYS.lastErrorTime));

    const lastErrorMessage = properties.getProperty(HEALTH_KEYS.lastErrorMessage) || "";

    sheet.appendRow([
        nowTime,
        new Date(periodStartTime),
        nowTime,
        expectedCount,
        totalCount,
        successCount,
        failureCount,
        successRate,
        lastSuccessTime,
        lastErrorTime,
        lastErrorMessage,
    ]);

    resetHealthCounters(properties);
}

function getOrCreateHealthSheet(spreadsheet) {
    let sheet = spreadsheet.getSheetByName(HEALTH_SHEET_NAME);

    if (sheet) {
        return sheet;
    }

    sheet = spreadsheet.insertSheet(HEALTH_SHEET_NAME);

    sheet.appendRow([
        "集計時刻",
        "対象開始",
        "対象終了",
        "予定実行回数",
        "実行回数",
        "成功数",
        "失敗数",
        "正常動作率",
        "最終成功時刻",
        "最終エラー時刻",
        "最終エラー内容",
    ]);

    return sheet;
}

function resetHealthCounters(properties) {
    properties.setProperty(HEALTH_KEYS.successCount, "0");

    properties.setProperty(HEALTH_KEYS.failureCount, "0");

    properties.setProperty(HEALTH_KEYS.periodStartTime, String(Date.now()));
}

function formatHealthTime(value) {
    if (!value) {
        return "";
    }

    return Utilities.formatDate(new Date(Number(value)), "Asia/Tokyo", "yyyy/MM/dd HH:mm:ss");
}
