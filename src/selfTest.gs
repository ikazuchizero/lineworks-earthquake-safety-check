// 外部通信なしで判定ロジックを確認するファイル

function runSelfTests() {
    const config = {
        quietHoursStart: 0,
        quietHoursEnd: 6,
    };

    const nightWindow = quietWindowFor("2026/11/23 03:00:00", config);
    assert(nightWindow !== null, "深夜は静穏時間になる");
    assert(
        compareTimes(nightWindow.windowEnd, nightWindow.windowStart) === 6 * 60 * 60 * 1000,
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

    const built = buildEarthquakeCandidate({
        id: "test-1",
        earthquake: {
            time: "2026/11/23 03:00:00",
            hypocenter: { name: "東京湾" },
            maxScale: 45,
        },
    });

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

    assert(
        isBetterQuietCandidate(
            { earthquakeTime: "2026/11/23 03:00:00", hypocenterName: "東京湾", maxScale: 45 },
            { earthquakeTime: "2026/11/23 03:00:00", hypocenterName: "UNKNOWN", maxScale: 45 }
        ),
        "同じ発生時刻なら震源地ありを優先する"
    );

    const url = buildP2pquakeUrl({
        p2pquakeApiUrl: "https://api.p2pquake.net/v2/jma/quake?limit=1",
        p2pquakeLimit: 10,
    });

    assert(url.indexOf("limit=10") !== -1, "limitを10に上書きする");
    assert(url.indexOf("limit=1&") === -1, "古いlimitは残らない");

    console.log("セルフテスト: すべて成功");
}

function assert(condition, message) {
    if (!condition) {
        throw new Error("セルフテスト失敗: " + message);
    }
}
