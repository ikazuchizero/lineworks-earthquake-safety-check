// スプレッドシートとシートの共通操作を管理するファイル

function getSpreadsheet(properties) {
    const spreadsheetId = properties.getProperty(PROPERTY_KEYS.spreadsheetId);

    if (spreadsheetId && String(spreadsheetId).trim() !== "") {
        return SpreadsheetApp.openById(String(spreadsheetId).trim());
    }

    const spreadsheet = SpreadsheetApp.getActiveSpreadsheet();

    if (!spreadsheet) {
        throw new Error(ERROR_MESSAGES.spreadsheetMissing);
    }

    return spreadsheet;
}

function getOrCreateSheet(spreadsheet, name, headers) {
    let sheet = spreadsheet.getSheetByName(name);

    if (!sheet) {
        sheet = spreadsheet.insertSheet(name);
    }

    syncHeaders(sheet, headers);

    return sheet;
}

// 旧版の4列ログなど、見出しが古い場合は現在の見出しへ更新する
function syncHeaders(sheet, headers) {
    if (sheet.getLastRow() === 0) {
        sheet.appendRow(headers);
        sheet.getRange(1, 1, 1, headers.length).setFontWeight("bold");
        sheet.setFrozenRows(1);
        return;
    }

    const width = Math.max(sheet.getLastColumn(), headers.length);
    const current = sheet.getRange(1, 1, 1, width).getValues()[0];
    const matches = headers.every(function (header, index) {
        return String(current[index] || "").trim() === header;
    });

    if (matches) {
        return;
    }

    sheet.getRange(1, 1, 1, headers.length).setValues([headers]);
    sheet.getRange(1, 1, 1, headers.length).setFontWeight("bold");
    sheet.setFrozenRows(1);
}
