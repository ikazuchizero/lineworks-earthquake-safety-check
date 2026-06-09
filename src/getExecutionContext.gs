// 実行時に必要な共通情報を取得するファイル

function getExecutionContext() {
    const properties = PropertiesService.getScriptProperties();

    const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(LOG_SHEET_NAME);

    return {
        properties: properties,
        formUrl: properties.getProperty("FORM_URL"),
        roomId: properties.getProperty("ROOM_ID"),
        sheet: sheet,
        nowTime: new Date(),
    };
}
