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
        .everyMinutes(CHECK_INTERVAL_MINUTES)
        .create();

    ScriptApp.newTrigger("writeHourlyHealthCheck")
        .timeBased()
        .everyHours(1)
        .create();

    // GAS APIではトリガーの実行間隔を取得できないため、設定した間隔を保存する
    properties.setProperty(PROPERTY_KEYS.checkIntervalMinutes, String(CHECK_INTERVAL_MINUTES));
    properties.setProperty(PROPERTY_KEYS.healthIntervalHours, "1");

    console.log(
        "トリガーを設定しました: checkAndNotifyEarthquake（" +
            CHECK_INTERVAL_MINUTES +
            "分ごと）, writeHourlyHealthCheck（1時間ごと）"
    );
}
