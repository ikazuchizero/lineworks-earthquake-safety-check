// 定期実行トリガーを設定するファイル

function setupTriggers() {
    const triggers = ScriptApp.getProjectTriggers();

    for (const trigger of triggers) {
        const handler = trigger.getHandlerFunction();

        if (handler === "checkAndNotifyEarthquake" || handler === "writeHourlyHealthCheck") {
            ScriptApp.deleteTrigger(trigger);
        }
    }

    ScriptApp.newTrigger("checkAndNotifyEarthquake")
        .timeBased()
        .everyMinutes(1)
        .create();

    ScriptApp.newTrigger("writeHourlyHealthCheck")
        .timeBased()
        .everyHours(1)
        .create();

    console.log(
        "トリガーを設定しました: checkAndNotifyEarthquake（1分ごと）, writeHourlyHealthCheck（1時間ごと）"
    );
}
