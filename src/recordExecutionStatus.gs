// 実行成功・失敗の回数をScript Propertiesに記録するファイル

function recordExecutionSuccess() {
    const properties = PropertiesService.getScriptProperties();

    ensureHealthPeriodStarted(properties);
    incrementHealthCount(properties, PROPERTY_KEYS.healthSuccessCount);
    properties.setProperty(PROPERTY_KEYS.healthLastSuccessTime, String(Date.now()));
}

function recordExecutionFailure(error) {
    const properties = PropertiesService.getScriptProperties();
    const message = error && error.message ? error.message : String(error);

    ensureHealthPeriodStarted(properties);
    incrementHealthCount(properties, PROPERTY_KEYS.healthFailureCount);
    properties.setProperty(PROPERTY_KEYS.healthLastErrorTime, String(Date.now()));
    properties.setProperty(PROPERTY_KEYS.healthLastErrorMessage, message);
}

function ensureHealthPeriodStarted(properties) {
    const periodStartTime = properties.getProperty(PROPERTY_KEYS.healthPeriodStartTime);

    if (periodStartTime) {
        return;
    }

    properties.setProperty(PROPERTY_KEYS.healthPeriodStartTime, String(Date.now()));
}

function incrementHealthCount(properties, key) {
    const count = Number(properties.getProperty(key) || 0);

    properties.setProperty(key, String(count + 1));
}
