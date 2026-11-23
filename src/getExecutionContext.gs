// 実行時に必要な共通情報を取得するファイル

function getExecutionContext(config, properties) {
    const props = properties || PropertiesService.getScriptProperties();
    const spreadsheet = getSpreadsheet(props);

    return {
        properties: props,
        spreadsheet: spreadsheet,
        logSheet: getOrCreateSheet(spreadsheet, SHEET_NAMES.log, LOG_HEADERS),
        stateSheet: getOrCreateSheet(spreadsheet, SHEET_NAMES.state, STATE_HEADERS),
        nowTime: new Date(),
        config: config,
    };
}
