// 地震ログを保持期間に合わせて整理するファイル

function cleanupLogs() {
    const lock = LockService.getScriptLock();

    if (!lock.tryLock(30000)) {
        console.warn("メイン処理が実行中のためログ整理をスキップしました");
        return 0;
    }

    try {
        const properties = PropertiesService.getScriptProperties();
        const config = loadConfig(properties);
        const spreadsheet = getSpreadsheet(properties);
        const deleted = cleanupLogsSheet(spreadsheet, config.logRetentionDays);

        properties.setProperty(PROPERTY_KEYS.lastLogCleanupAt, String(Date.now()));

        console.log(
            "地震ログを整理しました: " + deleted + "行削除（保持 " + config.logRetentionDays + "日）"
        );

        return deleted;
    } finally {
        lock.releaseLock();
    }
}

// ヘルスチェック実行中（すでにロック保持中）に呼ぶ内部関数
function cleanupLogsIfDue(properties, spreadsheet) {
    const lastCleanupAt = Number(
        properties.getProperty(PROPERTY_KEYS.lastLogCleanupAt) || 0
    );
    const oneDay = 24 * 60 * 60 * 1000;

    if (Date.now() - lastCleanupAt < oneDay) {
        return 0;
    }

    const retentionDays = readIntegerProperty(
        properties,
        PROPERTY_KEYS.logRetentionDays,
        CONFIG.logRetentionDays
    );

    if (retentionDays <= 0) {
        return 0;
    }

    const deleted = cleanupLogsSheet(spreadsheet, retentionDays);
    properties.setProperty(PROPERTY_KEYS.lastLogCleanupAt, String(Date.now()));

    if (deleted > 0) {
        console.log(
            "地震ログを整理しました: " + deleted + "行削除（保持 " + retentionDays + "日）"
        );
    }

    return deleted;
}

function cleanupLogsSheet(spreadsheet, retentionDays) {
    const sheet = getOrCreateSheet(spreadsheet, SHEET_NAMES.log, LOG_HEADERS);
    const lastRow = sheet.getLastRow();

    if (lastRow <= 1) {
        return 0;
    }

    const values = sheet.getRange(2, 1, lastRow - 1, 1).getValues();
    const cutoff = Date.now() - retentionDays * 24 * 60 * 60 * 1000;
    let deleteCount = 0;

    // ログは時系列で追記されるため、古い行は先頭に連続している
    for (let i = 0; i < values.length; i++) {
        const recordedAt = parseTokyoTime(values[i][0]);

        if (!recordedAt || recordedAt.getTime() >= cutoff) {
            break;
        }

        deleteCount++;
    }

    if (deleteCount > 0) {
        sheet.deleteRows(2, deleteCount);
    }

    return deleteCount;
}
