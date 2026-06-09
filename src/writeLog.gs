// スプレッドシートへログを書き込むファイル

function writeLog(sheet, time, eventId, scale, result) {
    if (!sheet) {
        return;
    }

    sheet.appendRow([time, eventId, scale, result]);
}
