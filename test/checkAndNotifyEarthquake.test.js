const assert = require("node:assert/strict");
const test = require("node:test");
const { MockProperties, MockResponse, createRuntime, earthquakeEvent } = require("./helpers/gasRuntime");

test("createEarthquakeMessage formats earthquake details and form URL", () => {
    const gas = createRuntime();
    const message = gas.createEarthquakeMessage(earthquakeEvent(), "5弱", "https://example.com/form");

    assert.match(message, /発生時刻：2024\/01\/02 12:04:05/);
    assert.match(message, /震源地：東京湾/);
    assert.match(message, /最大震度：5弱/);
    assert.match(message, /https:\/\/example\.com\/form/);
});

test("processEarthquakeValidation rejects duplicate events and logs the result", () => {
    const properties = new MockProperties({ LAST_NOTIFIED_ID: "event-1" });
    const gas = createRuntime({ properties });
    const context = {
        properties,
        sheet: gas.logSheet,
        nowTime: new gas.context.Date(),
    };

    const result = gas.processEarthquakeValidation(context, earthquakeEvent());

    assert.equal(result, false);
    assert.equal(gas.logSheet.rows.length, 1);
    assert.deepEqual(Array.from(gas.logSheet.rows[0].slice(1)), ["event-1", "5弱", "重複パス"]);
});

test("processEarthquakeValidation throws when earthquake id is missing", () => {
    const gas = createRuntime();
    const context = {
        properties: gas.properties,
        sheet: gas.logSheet,
        nowTime: new gas.context.Date(),
    };

    assert.throws(
        () => gas.processEarthquakeValidation(context, earthquakeEvent({ id: "" })),
        /地震ID取得失敗/,
    );
});

test("getLatestEarthquake returns the newest API event", () => {
    const gas = createRuntime({
        fetchQueue: [
            new MockResponse(200, JSON.stringify([earthquakeEvent(), earthquakeEvent({ id: "event-2" })])),
        ],
    });

    assert.equal(gas.getLatestEarthquake().id, "event-1");
});

test("getLatestEarthquake throws on API failure", () => {
    const gas = createRuntime({
        fetchQueue: [new MockResponse(500, "server error")],
    });

    assert.throws(() => gas.getLatestEarthquake(), /地震API取得失敗 HTTP:500 server error/);
});

test("checkAndNotifyEarthquake sends notification and records success", () => {
    const properties = new MockProperties({
        CLIENT_ID: "client-1",
        CLIENT_SECRET: "secret-1",
        SERVICE_ACCT: "service-1",
        PRIVATE_KEY: "-----BEGIN PRIVATE KEY-----abc-----END PRIVATE KEY-----",
        BOT_ID: "bot-1",
        ROOM_ID: "room-1",
        FORM_URL: "https://example.com/form",
    });
    const gas = createRuntime({
        properties,
        fetchQueue: [
            new MockResponse(200, JSON.stringify([earthquakeEvent()])),
            new MockResponse(200, JSON.stringify({ access_token: "access-1" })),
            new MockResponse(200, "{}"),
        ],
    });

    gas.checkAndNotifyEarthquake();

    assert.equal(gas.fetchCalls.length, 3);
    assert.equal(properties.getProperty("LAST_NOTIFIED_ID"), "event-1");
    assert.equal(properties.getProperty("HEALTH_SUCCESS_COUNT"), "1");
    assert.equal(gas.logSheet.rows.length, 1);
    assert.deepEqual(Array.from(gas.logSheet.rows[0].slice(1)), ["event-1", "5弱", "送信成功"]);
    assert.equal(gas.lock.released, true);
});
