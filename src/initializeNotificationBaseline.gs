// 初回導入時に、現在取得できる地震を「通知済み」として記録するファイル
// 過去の地震を「先ほど発生」として送信しないためのベースライン作成に使う。

function initializeNotificationBaseline() {
    const lock = LockService.getScriptLock();

    if (!lock.tryLock(30000)) {
        throw new Error("別の処理が実行中のためベースラインを作成できませんでした");
    }

    try {
        const properties = PropertiesService.getScriptProperties();
        const allowWithTriggers = readBooleanProperty(
            properties,
            PROPERTY_KEYS.allowBaselineWithTriggers,
            false
        );

        // 稼働中のトリガーがある状態で実行すると、直前の地震を
        // 「通知済み」にして送らない事故につながるため、既定では拒否する
        if (!allowWithTriggers) {
            const activeTriggers = ScriptApp.getProjectTriggers().filter(function (trigger) {
                const handler = trigger.getHandlerFunction();

                return (
                    handler === "checkAndNotifyEarthquake" ||
                    handler === "writeHourlyHealthCheck"
                );
            });

            if (activeTriggers.length > 0) {
                throw new Error(ERROR_MESSAGES.baselineTriggersActive);
            }
        }

        const config = loadConfig(properties);
        const context = getExecutionContext(config, properties);
        const state = loadState(context);
        const events = fetchEarthquakes(config);
        const now = context.nowTime;
        const notifiedAt = now.toISOString();
        let count = 0;

        for (const event of events) {
            const built = buildEarthquakeCandidate(event);

            if (!built.candidate) {
                continue;
            }

            const candidate = built.candidate;

            if (
                state.notified[candidate.dedupeKey] ||
                state.notifiedByTime[candidate.earthquakeTime]
            ) {
                continue;
            }

            state.notified[candidate.dedupeKey] = {
                dedupeKey: candidate.dedupeKey,
                eventId: candidate.eventId || null,
                earthquakeTime: candidate.earthquakeTime,
                hypocenterName: candidate.hypocenterName,
                maxScale: candidate.maxScale,
                notifiedAt: notifiedAt,
                baseline: true,
            };
            state.notifiedByTime[candidate.earthquakeTime] = {
                dedupeKey: candidate.dedupeKey,
                notifiedAt: notifiedAt,
            };
            count++;
        }

        // 初回ベースラインでは、過去の静穏バッチや保留を持ち越さない
        state.pendingUnknown = {};
        state.quietBatch = null;
        state.quietRelease = null;
        state.quietCompletedThrough = null;
        markStateChanged(state);

        saveState(context, state);

        console.log(
            LOG_MESSAGES.baseline +
                ": " +
                count +
                "件（通知は送信していません）"
        );

        return count;
    } finally {
        lock.releaseLock();
    }
}
