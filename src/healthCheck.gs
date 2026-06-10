// 実行状態の記録と1時間ごとのヘルスチェックを管理するファイル

// メイン処理の成功回数と最終成功時刻を記録する。
function recordExecutionSuccess() {
    const properties = PropertiesService.getScriptProperties();

    ensureHealthPeriodStarted(properties);
    incrementHealthCount(properties, HEALTH_KEYS.successCount);
    properties.setProperty(HEALTH_KEYS.lastSuccessTime, String(Date.now()));
}

// メイン処理の失敗回数と最終エラー情報を記録する。
function recordExecutionFailure(error) {
    const properties = PropertiesService.getScriptProperties();

    ensureHealthPeriodStarted(properties);
    incrementHealthCount(properties, HEALTH_KEYS.failureCount);
    properties.setProperty(HEALTH_KEYS.lastErrorTime, String(Date.now()));
    properties.setProperty(HEALTH_KEYS.lastErrorMessage, error.message);
}

// 1時間分の実行状態をヘルスチェックシートへ追記する。
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
    const successRate = successCount / expectedCount;
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

// ヘルスチェック集計期間の開始時刻を初期化する。
function ensureHealthPeriodStarted(properties) {
    if (properties.getProperty(HEALTH_KEYS.periodStartTime)) {
        return;
    }

    properties.setProperty(HEALTH_KEYS.periodStartTime, String(Date.now()));
}

// 指定されたヘルスチェック用カウンタを1増やす。
function incrementHealthCount(properties, key) {
    const count = Number(properties.getProperty(key) || 0);

    properties.setProperty(key, String(count + 1));
}

// ヘルスチェックシートを取得し、存在しない場合は作成する。
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

// ヘルスチェック用カウンタを次の集計期間向けにリセットする。
function resetHealthCounters(properties) {
    properties.setProperty(HEALTH_KEYS.successCount, "0");
    properties.setProperty(HEALTH_KEYS.failureCount, "0");
    properties.setProperty(HEALTH_KEYS.periodStartTime, String(Date.now()));
}

// Script Propertiesに保存された時刻を表示用文字列へ変換する。
function formatHealthTime(value) {
    if (!value) {
        return "";
    }

    return Utilities.formatDate(new Date(Number(value)), "Asia/Tokyo", "yyyy/MM/dd HH:mm:ss");
}
