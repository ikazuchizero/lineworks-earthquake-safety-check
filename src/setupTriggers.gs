// 定期実行トリガーを設定するファイル

function setupTriggers() {
    const properties = PropertiesService.getScriptProperties();
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

    // GAS APIではトリガーの実行間隔を取得できないため、設定した間隔を保存する
    properties.setProperty(PROPERTY_KEYS.checkIntervalMinutes, "1");
    properties.setProperty(PROPERTY_KEYS.healthIntervalHours, "1");

    console.log(
        "トリガーを設定しました: checkAndNotifyEarthquake（1分ごと）, writeHourlyHealthCheck（1時間ごと）"
    );
}
