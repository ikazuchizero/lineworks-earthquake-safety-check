// 例外発生時のログ処理を管理するファイル

function processError(context, error) {
    writeLog(context.sheet, context.nowTime, "-", "-", "エラー: " + error.message);
}
