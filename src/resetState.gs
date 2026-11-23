// テスト用に通知状態をリセットするファイル

function resetState() {
    const properties = PropertiesService.getScriptProperties();
    const spreadsheet = getSpreadsheet(properties);
    const stateSheet = getOrCreateSheet(
        spreadsheet,
        SHEET_NAMES.state,
        STATE_HEADERS
    );

    // 設定が未完成でもリセットできるよう、configは使わない
    clearState({ stateSheet: stateSheet });

    properties.deleteProperty(PROPERTY_KEYS.lastRoutineLogTime);
    properties.deleteProperty(PROPERTY_KEYS.failureFingerprint);
    properties.deleteProperty(PROPERTY_KEYS.failureNotifyTime);

    console.log("通知状態をリセットしました");
}

// 旧版の関数名との互換用
function resetLastNotifiedId() {
    resetState();
}
