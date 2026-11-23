// 1時間ごとの正常動作率をヘルスチェックシートへ記録するファイル

function writeHourlyHealthCheck() {
    const lock = LockService.getScriptLock();

    if (!lock.tryLock(1000)) {
        console.log("別の実行中のためヘルスチェックをスキップしました");
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

        const expectedCount = CONFIG.expectedRunsPerHour;
        const totalCount = successCount + failureCount;
        const successRate = expectedCount === 0 ? 0 : successCount / expectedCount;

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
        ]);

        const rateCell = sheet.getRange(sheet.getLastRow(), 8);
        rateCell.setNumberFormat("0.0%");

        resetHealthCounters(properties);
    } finally {
        lock.releaseLock();
    }
}

function resetHealthCounters(properties) {
    properties.setProperty(PROPERTY_KEYS.healthSuccessCount, "0");
    properties.setProperty(PROPERTY_KEYS.healthFailureCount, "0");
    properties.setProperty(PROPERTY_KEYS.healthPeriodStartTime, String(Date.now()));
}

function formatHealthTime(value) {
    if (!value) {
        return "";
    }

    return Utilities.formatDate(new Date(Number(value)), "Asia/Tokyo", "yyyy/MM/dd HH:mm:ss");
}
