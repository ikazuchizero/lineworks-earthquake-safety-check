// メイン処理を管理するファイル

function checkAndNotifyEarthquake() {
    const lock = LockService.getScriptLock();

    if (!lock.tryLock(1000)) {
        return;
    }

    const context = getExecutionContext();

    try {
        // 地震取得・通知判定
        const event = getLatestEarthquake();

        if (!processEarthquakeValidation(context, event)) {
            recordExecutionSuccess();
            return;
        }

        // LINE WORKS通知
        notifyEarthquake(context, event);

        recordExecutionSuccess();
    } catch (e) {
        processError(context, e);
        recordExecutionFailure(e);
        throw e;
    } finally {
        lock.releaseLock();
    }
}
