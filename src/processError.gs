// 例外発生時のログ処理と障害通知を管理するファイル

function processError(context, error) {
    const message = error && error.message ? error.message : String(error);

    try {
        writeLog(context, {
            time: new Date(),
            eventId: "-",
            earthquakeTime: "",
            hypocenterName: "-",
            result: "エラー",
            detail: message,
        });
    } catch (logError) {
        console.error("エラーログの記録に失敗しました: " + logError.message);
    }

    try {
        notifyFailure(context, error);
    } catch (notifyError) {
        console.error("障害通知に失敗しました: " + notifyError.message);
    }
}
