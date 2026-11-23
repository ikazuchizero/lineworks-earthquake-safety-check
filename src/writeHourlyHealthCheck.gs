// 1時間ごとの正常動作率をヘルスチェックシートへ記録するファイル

function writeHourlyHealthCheck() {
    const lock = LockService.getScriptLock();

    // メイン処理とロックを共有する。最大30秒待ち、取れなければ今回の記録は諦める
    if (!lock.tryLock(30000)) {
        console.warn(
            "メイン処理が実行中のためヘルスチェックをスキップしました（次回の集計に含まれます）"
        );
        return;
    }

    try {
        const properties = PropertiesService.getScriptProperties();
        const spreadsheet = getSpreadsheet(properties);
        const sheet = getOrCreateSheet(spreadsheet, SHEET_NAMES.health, HEALTH_HEADERS);

        const nowTime = new Date();
        const periodStartTime = Number(
            properties.getProperty(PROPERTY_KEYS.healthPeriodStartTime) || Date.now()
        );
        const successCount = Number(
            properties.getProperty(PROPERTY_KEYS.healthSuccessCount) || 0
        );
        const failureCount = Number(
            properties.getProperty(PROPERTY_KEYS.healthFailureCount) || 0
        );
        const skippedCount = Number(
            properties.getProperty(PROPERTY_KEYS.healthSkippedCount) || 0
        );

        const expectedCount = CONFIG.expectedRunsPerHour;
        const totalCount = successCount + failureCount;
        // 予定回数を超えた場合や、ヘルスチェック自体がスキップされた時間が
        // 含まれる場合でも100%を超えないようにする
        const successRate =
            expectedCount === 0 ? 0 : Math.min(successCount / expectedCount, 1);
        // 実際に実行できた回数に対する成功率（ロック競合の影響を除く）
        const executionSuccessRate =
            totalCount === 0 ? 0 : successCount / totalCount;

        const lastSuccessTime = formatHealthTime(
            properties.getProperty(PROPERTY_KEYS.healthLastSuccessTime)
        );
        const lastErrorTime = formatHealthTime(
            properties.getProperty(PROPERTY_KEYS.healthLastErrorTime)
        );
        const lastErrorMessage =
            properties.getProperty(PROPERTY_KEYS.healthLastErrorMessage) || "";

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
            skippedCount,
            executionSuccessRate,
        ]);

        const lastRow = sheet.getLastRow();
        sheet.getRange(lastRow, 8).setNumberFormat("0.0%");
        sheet.getRange(lastRow, 13).setNumberFormat("0.0%");

        resetHealthCounters(properties);

        try {
            cleanupLogsIfDue(properties, spreadsheet);
        } catch (cleanupError) {
            console.error("地震ログの整理に失敗しました: " + cleanupError.message);
        }
    } finally {
        lock.releaseLock();
    }
}

function resetHealthCounters(properties) {
    properties.setProperty(PROPERTY_KEYS.healthSuccessCount, "0");
    properties.setProperty(PROPERTY_KEYS.healthFailureCount, "0");
    properties.setProperty(PROPERTY_KEYS.healthSkippedCount, "0");
    properties.setProperty(PROPERTY_KEYS.healthPeriodStartTime, String(Date.now()));
}

function formatHealthTime(value) {
    if (!value) {
        return "";
    }

    return Utilities.formatDate(new Date(Number(value)), "Asia/Tokyo", "yyyy/MM/dd HH:mm:ss");
}
