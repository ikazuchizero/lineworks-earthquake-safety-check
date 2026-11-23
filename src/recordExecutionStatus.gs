// 実行成功・失敗・スキップの回数をScript Propertiesに記録するファイル

const MAX_HEALTH_ERROR_MESSAGE_LENGTH = 1000;
const HEALTH_COUNTER_LOCK_WAIT_MS = 5000;

function recordExecutionSuccess() {
    withHealthCounterLock(function () {
        const properties = PropertiesService.getScriptProperties();

        ensureHealthPeriodStarted(properties);
        incrementHealthCount(properties, PROPERTY_KEYS.healthSuccessCount);
        properties.setProperty(PROPERTY_KEYS.healthLastSuccessTime, String(Date.now()));
    });
}

function recordExecutionFailure(error) {
    const message = error && error.message ? error.message : String(error);

    withHealthCounterLock(function () {
        const properties = PropertiesService.getScriptProperties();

        ensureHealthPeriodStarted(properties);
        incrementHealthCount(properties, PROPERTY_KEYS.healthFailureCount);
        properties.setProperty(PROPERTY_KEYS.healthLastErrorTime, String(Date.now()));
        // Script Propertiesの値上限（約9KB）を超えないように切り詰める
        properties.setProperty(
            PROPERTY_KEYS.healthLastErrorMessage,
            truncateText(message, MAX_HEALTH_ERROR_MESSAGE_LENGTH)
        );
    });
}

function recordExecutionSkipped() {
    withHealthCounterLock(function () {
        const properties = PropertiesService.getScriptProperties();

        ensureHealthPeriodStarted(properties);
        incrementHealthCount(properties, PROPERTY_KEYS.healthSkippedCount);
    });
}

// スキップ記録はメインのスクリプトロックを取れないときに走るため、
// ヘルスカウンタ専用のユーザーロックで read/reset と直列化する
function withHealthCounterLock(callback) {
    const lock = LockService.getUserLock();

    if (!lock.tryLock(HEALTH_COUNTER_LOCK_WAIT_MS)) {
        console.warn("ヘルスカウンタのロックが取れませんでした");
        return null;
    }

    try {
        return callback();
    } finally {
        lock.releaseLock();
    }
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
