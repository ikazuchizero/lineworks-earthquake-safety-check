// 外部通信なしで判定ロジックを確認するファイル
// GASエディタの runSelfTests() と、Node の tests/run-tests.js の両方から実行する

function runSelfTests() {
    testQuietWindowBasics();
    testCandidateBasics();
    testUrlBuild();
    testSelection();

    console.log("セルフテスト: すべて成功");
}

function selfTestConfig(overrides) {
    const config = {
        notifyScale: 45,
        unknownHypocenterHoldSeconds: 600,
        quietHoursStart: 0,
        quietHoursEnd: 6,
        p2pquakeApiUrl: "https://api.p2pquake.net/v2/jma/quake?limit=1",
        p2pquakeLimit: 10,
        stateRetentionDays: 30,
        maxNotifiedRecords: 500,
        failureNotifySuppressSeconds: 3600,
        formTitlePrefix: "安否確認",
        expectedRunsPerHour: 60,
    };

    if (overrides) {
        for (const key of Object.keys(overrides)) {
            config[key] = overrides[key];
        }
    }

    return config;
}

function selfTestContext() {
    const store = {};

    return {
        properties: {
            getProperty: function (key) {
                return Object.prototype.hasOwnProperty.call(store, key)
                    ? store[key]
                    : null;
            },
            setProperty: function (key, value) {
                store[key] = String(value);
            },
        },
        logSheet: null,
        nowTime: new Date(),
        config: selfTestConfig(),
    };
}

function selfTestEvent(time, hypocenterName, maxScale) {
    const earthquake = {
        time: time,
        maxScale: maxScale,
    };

    if (hypocenterName) {
        earthquake.hypocenter = { name: hypocenterName };
    }

    return {
        id: "test-" + time + "-" + (hypocenterName || "UNKNOWN"),
        earthquake: earthquake,
    };
}

function selfTestNow(text) {
    return parseTokyoTime(text);
}

function selfTestPending(earthquakeTime, firstSeenAt, maxScale) {
    return {
        dedupeKey: earthquakeTime + "|UNKNOWN",
        eventId: "pending-" + earthquakeTime,
        earthquakeTime: earthquakeTime,
        hypocenterName: "UNKNOWN",
        maxScale: maxScale === undefined ? 45 : maxScale,
        firstSeenAt: firstSeenAt,
    };
}

function testQuietWindowBasics() {
    const config = selfTestConfig();
    const nightWindow = quietWindowFor("2026/11/23 03:00:00", config);

    assert(nightWindow !== null, "深夜は静穏時間になる");
    assert(
        compareTimes(nightWindow.windowEnd, nightWindow.windowStart) ===
            6 * 60 * 60 * 1000,
        "静穏時間の長さは6時間"
    );
    assert(
        Utilities.formatDate(
            parseTokyoTime(nightWindow.windowStart),
            "Asia/Tokyo",
            "H"
        ) === "0",
        "静穏時間の開始は0時"
    );
    assert(
        quietWindowFor("2026/11/23 12:00:00", config) === null,
        "昼間は静穏時間ではない"
    );

    const wrapping = selfTestConfig({ quietHoursStart: 22, quietHoursEnd: 6 });
    const beforeMidnight = quietWindowFor("2026/11/23 23:00:00", wrapping);
    const afterMidnight = quietWindowFor("2026/11/24 02:00:00", wrapping);

    assert(beforeMidnight !== null, "日をまたぐ静穏時間（開始側）");
    assert(afterMidnight !== null, "日をまたぐ静穏時間（終了側）");
    assert(
        String(beforeMidnight.windowStart) === String(afterMidnight.windowStart),
        "同じ夜の静穏時間は同じ開始時刻になる"
    );
}

function testCandidateBasics() {
    const built = buildEarthquakeCandidate(
        selfTestEvent("2026/11/23 03:00:00", "東京湾", 45)
    );

    assert(built.candidate !== null, "地震候補を作成できる");
    assert(
        built.candidate.dedupeKey === "2026/11/23 03:00:00|東京湾",
        "dedupe_keyは発生時刻と震源地"
    );
    assert(scaleText(45) === "5弱", "震度45は5弱");
    assert(
        formatEarthquakeTime("2026/11/23 03:00:00") === "2026/11/23 03:00:00",
        "発生時刻を東京時間で表示できる"
    );

    const unknown = buildEarthquakeCandidate(
        selfTestEvent("2026/11/23 03:00:00", "", 45)
    );

    assert(unknown.candidate.hypocenterName === "UNKNOWN", "震源地なしはUNKNOWN");

    assert(
        isBetterQuietCandidate(
            {
                earthquakeTime: "2026/11/23 03:00:00",
                hypocenterName: "東京湾",
                maxScale: 45,
            },
            {
                earthquakeTime: "2026/11/23 03:00:00",
                hypocenterName: "UNKNOWN",
                maxScale: 45,
            }
        ),
        "同じ発生時刻なら震源地ありを優先する"
    );
}

function testUrlBuild() {
    const url = buildP2pquakeUrl(selfTestConfig());

    assert(url.indexOf("limit=10") !== -1, "limitを10に上書きする");
    assert(url.indexOf("limit=1&") === -1, "古いlimitは残らない");
}

function testSelection() {
    const config = selfTestConfig();
    const context = selfTestContext();

    // 通常時間帯はそのまま通知対象になる
    let state = createEmptyState();
    let targets = selectNotificationTargets(
        [selfTestEvent("2026/11/23 12:00:00", "東京湾", 45)],
        state,
        config,
        selfTestNow("2026/11/23 12:01:00"),
        context
    );
    assert(targets.length === 1, "通常の通知対象を選べる");
    assert(targets[0].hypocenterName === "東京湾", "震源地が正しい");

    // しきい値未満は通知しない
    state = createEmptyState();
    targets = selectNotificationTargets(
        [selfTestEvent("2026/11/23 12:00:00", "東京湾", 40)],
        state,
        config,
        selfTestNow("2026/11/23 12:01:00"),
        context
    );
    assert(targets.length === 0, "しきい値未満は通知しない");

    // 同じ重複キーは通知しない
    state = createEmptyState();
    state.notified["2026/11/23 12:00:00|東京湾"] = {
        earthquakeTime: "2026/11/23 12:00:00",
    };
    targets = selectNotificationTargets(
        [selfTestEvent("2026/11/23 12:00:00", "東京湾", 45)],
        state,
        config,
        selfTestNow("2026/11/23 12:01:00"),
        context
    );
    assert(targets.length === 0, "通知済みの重複キーは通知しない");

    // 同じ発生時刻なら震源地が違っても通知しない
    state = createEmptyState();
    state.notifiedByTime["2026/11/23 12:00:00"] = {
        dedupeKey: "2026/11/23 12:00:00|別の震源地",
        notifiedAt: new Date().toISOString(),
    };
    targets = selectNotificationTargets(
        [selfTestEvent("2026/11/23 12:00:00", "東京湾", 45)],
        state,
        config,
        selfTestNow("2026/11/23 12:01:00"),
        context
    );
    assert(targets.length === 0, "同じ発生時刻は震源地が違っても通知しない");

    // UNKNOWNは即通知せず保留する
    state = createEmptyState();
    targets = selectNotificationTargets(
        [selfTestEvent("2026/11/23 12:00:00", "", 45)],
        state,
        config,
        selfTestNow("2026/11/23 12:01:00"),
        context
    );
    assert(targets.length === 0, "UNKNOWNは即通知しない");
    assert(
        !!state.pendingUnknown["2026/11/23 12:00:00"],
        "UNKNOWNを保留する"
    );

    // 保留中に震源地ありの続報が来たら、そちらを通知する
    targets = selectNotificationTargets(
        [selfTestEvent("2026/11/23 12:00:00", "東京湾", 45)],
        state,
        config,
        selfTestNow("2026/11/23 12:03:00"),
        context
    );
    assert(targets.length === 1, "続報の震源地ありを通知する");
    assert(targets[0].hypocenterName === "東京湾", "続報側が選ばれる");

    // 保留期限を過ぎたらUNKNOWNのまま通知する
    state = createEmptyState();
    state.pendingUnknown["2026/11/23 12:00:00"] = selfTestPending(
        "2026/11/23 12:00:00",
        "2026-11-23T12:00:00+09:00"
    );
    targets = selectNotificationTargets(
        [selfTestEvent("2026/11/23 12:00:00", "", 45)],
        state,
        config,
        selfTestNow("2026/11/23 12:10:00"),
        context
    );
    assert(targets.length === 1, "保留期限切れのUNKNOWNを通知する");
    assert(targets[0].hypocenterName === "UNKNOWN", "UNKNOWNのまま通知する");

    // APIから消えた保留分も期限切れなら通知する
    state = createEmptyState();
    state.pendingUnknown["2026/11/23 12:00:00"] = selfTestPending(
        "2026/11/23 12:00:00",
        "2026-11-23T12:00:00+09:00"
    );
    targets = selectNotificationTargets(
        [],
        state,
        config,
        selfTestNow("2026/11/23 12:10:00"),
        context
    );
    assert(targets.length === 1, "APIから消えた保留分も期限切れなら通知する");

    // 静穏時間は保留し、バッチを作る
    state = createEmptyState();
    targets = selectNotificationTargets(
        [selfTestEvent("2026/11/23 03:00:00", "東京湾", 45)],
        state,
        config,
        selfTestNow("2026/11/23 03:01:00"),
        context
    );
    assert(targets.length === 0, "静穏時間は通知しない");
    assert(!!state.quietBatch, "静穏バッチを保存する");

    // 静穏時間が終わったら1件通知する
    const released = selectNotificationTargets(
        [],
        state,
        config,
        selfTestNow("2026/11/23 06:05:00"),
        context
    );
    assert(released.length === 1, "静穏時間終了後に通知する");
    assert(
        released[0].quietWindowEnd === state.quietBatch.windowEnd,
        "quietWindowEndが付く"
    );

    // 静穏時間中、同じ時間帯で選ばれなかったUNKNOWNは漏らさない
    state = createEmptyState();
    state.quietBatch = {
        windowStart: "2026-11-22T15:00:00.000Z",
        windowEnd: "2026-11-22T21:00:00.000Z",
        candidate: {
            eventId: "quiet-1",
            earthquakeTime: "2026/11/23 02:00:00",
            hypocenterName: "東京湾",
            maxScale: 45,
            dedupeKey: "2026/11/23 02:00:00|東京湾",
        },
    };
    state.pendingUnknown["2026/11/23 03:00:00"] = selfTestPending(
        "2026/11/23 03:00:00",
        "2026-11-23T03:00:00+09:00"
    );
    targets = selectNotificationTargets(
        [],
        state,
        config,
        selfTestNow("2026/11/23 03:30:00"),
        context
    );
    assert(targets.length === 0, "静穏時間中にUNKNOWNを通知しない");

    // 静穏時間が終わればバッチ1件だけ通知し、同時間帯の保留は片付ける
    targets = selectNotificationTargets(
        [],
        state,
        config,
        selfTestNow("2026/11/23 06:05:00"),
        context
    );
    assert(targets.length === 1, "静穏終了後は1件だけ通知する");
    assert(
        targets[0].earthquakeTime === "2026/11/23 02:00:00",
        "静穏バッチの候補が選ばれる"
    );
    assert(
        !state.pendingUnknown["2026/11/23 03:00:00"],
        "同じ時間帯の保留は片付ける"
    );

    // しきい値が上がった場合、静穏バッチは通知せず破棄する
    const raised = selfTestConfig({ notifyScale: 50 });
    state = createEmptyState();
    state.quietBatch = {
        windowStart: "2026-11-22T15:00:00.000Z",
        windowEnd: "2026-11-22T21:00:00.000Z",
        candidate: {
            eventId: "quiet-2",
            earthquakeTime: "2026/11/23 03:00:00",
            hypocenterName: "東京湾",
            maxScale: 45,
            dedupeKey: "2026/11/23 03:00:00|東京湾",
        },
    };
    targets = selectNotificationTargets(
        [],
        state,
        raised,
        selfTestNow("2026/11/23 06:05:00"),
        context
    );
    assert(targets.length === 0, "しきい値未満の静穏バッチは通知しない");
    assert(state.quietBatch === null, "しきい値未満の静穏バッチは破棄する");

    // 同じ発生時刻にUNKNOWNと震源地ありが混在したら震源地ありを選ぶ
    state = createEmptyState();
    targets = selectNotificationTargets(
        [
            selfTestEvent("2026/11/23 12:00:00", "", 45),
            selfTestEvent("2026/11/23 12:00:00", "東京湾", 45),
        ],
        state,
        config,
        selfTestNow("2026/11/23 12:01:00"),
        context
    );
    assert(targets.length === 1, "同じ発生時刻は1件に絞る");
    assert(targets[0].hypocenterName === "東京湾", "震源地ありを優先する");
}

function assert(condition, message) {
    if (!condition) {
        throw new Error("セルフテスト失敗: " + message);
    }
}
