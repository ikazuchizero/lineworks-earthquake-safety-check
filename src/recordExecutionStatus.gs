// 実行成功・失敗・スキップの回数をScript Propertiesに記録するファイル

const MAX_HEALTH_ERROR_MESSAGE_LENGTH = 1000;

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
    // Script Propertiesの値上限（約9KB）を超えないように切り詰める
    properties.setProperty(
        PROPERTY_KEYS.healthLastErrorMessage,
        truncateText(message, MAX_HEALTH_ERROR_MESSAGE_LENGTH)
    );
}

function recordExecutionSkipped() {
    const properties = PropertiesService.getScriptProperties();

    ensureHealthPeriodStarted(properties);
    incrementHealthCount(properties, PROPERTY_KEYS.healthSkippedCount);
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

function truncateText(value, maxLength) {
    const text = String(value === null || value === undefined ? "" : value);

    if (text.length <= maxLength) {
        return text;
    }

    return text.slice(0, maxLength - 1) + "…";
}
