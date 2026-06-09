// 実行成功・失敗の回数をScript Propertiesに記録するファイル

function recordExecutionSuccess() {
    const properties = PropertiesService.getScriptProperties();

    ensureHealthPeriodStarted(properties);

    incrementHealthCount(properties, HEALTH_KEYS.successCount);

    properties.setProperty(HEALTH_KEYS.lastSuccessTime, String(Date.now()));
}

function recordExecutionFailure(error) {
    const properties = PropertiesService.getScriptProperties();

    ensureHealthPeriodStarted(properties);

    incrementHealthCount(properties, HEALTH_KEYS.failureCount);

    properties.setProperty(HEALTH_KEYS.lastErrorTime, String(Date.now()));

    properties.setProperty(HEALTH_KEYS.lastErrorMessage, error.message);
}

function ensureHealthPeriodStarted(properties) {
    const periodStartTime = properties.getProperty(HEALTH_KEYS.periodStartTime);

    if (periodStartTime) {
        return;
    }

    properties.setProperty(HEALTH_KEYS.periodStartTime, String(Date.now()));
}

function incrementHealthCount(properties, key) {
    const count = Number(properties.getProperty(key) || 0);

    properties.setProperty(key, String(count + 1));
}
