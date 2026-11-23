// メイン処理を管理するファイル

function checkAndNotifyEarthquake() {
    const lock = LockService.getScriptLock();

    if (!lock.tryLock(1000)) {
        try {
            recordExecutionSkipped();
        } catch (e) {
            console.error("スキップ回数の記録に失敗しました: " + e.message);
        }

        console.log("別の実行中のためスキップしました");
        return;
    }

    const properties = PropertiesService.getScriptProperties();
    let context = null;
    let state = null;

    try {
        const config = loadConfig(properties);
        context = getExecutionContext(config, properties);
        state = loadState(context);

        const events = fetchEarthquakes(config);
        const targets = selectNotificationTargets(
            events,
            state,
            config,
            context.nowTime,
            context
        );

        // 保留・静穏バッチの更新があった場合だけ送信前に保存する
        if (state.dirty) {
            saveState(context, state);
        }

        targets.sort(function (left, right) {
            return compareTimes(left.earthquakeTime, right.earthquakeTime);
        });

        for (const target of targets) {
            notifyEarthquake(context, state, target);
        }

        recordExecutionSuccess();
    } catch (e) {
        // 設定不備などでcontextを作れない場合も、分かる範囲で障害通知する
        processError(context || buildFailureContext(properties), e);
        recordExecutionFailure(e);
        throw e;
    } finally {
        if (context && state && state.dirty) {
            try {
                saveState(context, state);
            } catch (saveError) {
                console.error("状態の保存に失敗しました: " + saveError.message);
            }
        }

        lock.releaseLock();
    }
}

// 設定読み込み前に失敗した場合の最低限のコンテキスト
function buildFailureContext(properties) {
    const maintenanceRoomId =
        properties.getProperty(PROPERTY_KEYS.maintenanceRoomId) ||
        properties.getProperty(PROPERTY_KEYS.roomId) ||
        "";

    return {
        properties: properties,
        logSheet: null,
        config: {
            maintenanceRoomId: maintenanceRoomId,
            failureNotifySuppressSeconds: CONFIG.failureNotifySuppressSeconds,
        },
    };
}
