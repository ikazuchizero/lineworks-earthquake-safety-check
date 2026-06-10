const assert = require("node:assert/strict");
const test = require("node:test");
const { MockProperties, MockSpreadsheet, createRuntime } = require("./helpers/gasRuntime");

test("recordExecutionSuccess increments success count and sets last success time", () => {
    const properties = new MockProperties({ HEALTH_SUCCESS_COUNT: "2" });
    const gas = createRuntime({ properties, nowMs: 123456789 });

    gas.recordExecutionSuccess();

    assert.equal(properties.getProperty("HEALTH_SUCCESS_COUNT"), "3");
    assert.equal(properties.getProperty("HEALTH_LAST_SUCCESS_TIME"), "123456789");
    assert.equal(properties.getProperty("HEALTH_PERIOD_START_TIME"), "123456789");
});

test("recordExecutionFailure increments failure count and stores error details", () => {
    const properties = new MockProperties({ HEALTH_FAILURE_COUNT: "1" });
    const gas = createRuntime({ properties, nowMs: 123456789 });

    gas.recordExecutionFailure(new Error("boom"));

    assert.equal(properties.getProperty("HEALTH_FAILURE_COUNT"), "2");
    assert.equal(properties.getProperty("HEALTH_LAST_ERROR_TIME"), "123456789");
    assert.equal(properties.getProperty("HEALTH_LAST_ERROR_MESSAGE"), "boom");
});

test("writeHourlyHealthCheck appends summary row and resets counters", () => {
    const properties = new MockProperties({
        HEALTH_PERIOD_START_TIME: String(Date.parse("2024-01-02T02:04:05Z")),
        HEALTH_SUCCESS_COUNT: "58",
        HEALTH_FAILURE_COUNT: "2",
        HEALTH_LAST_SUCCESS_TIME: String(Date.parse("2024-01-02T03:00:00Z")),
        HEALTH_LAST_ERROR_TIME: String(Date.parse("2024-01-02T02:30:00Z")),
        HEALTH_LAST_ERROR_MESSAGE: "network",
    });
    const gas = createRuntime({
        properties,
        spreadsheet: new MockSpreadsheet(),
        nowMs: Date.parse("2024-01-02T03:04:05Z"),
    });

    gas.writeHourlyHealthCheck();

    const sheet = gas.spreadsheet.getSheetByName("ヘルスチェック");
    assert.equal(sheet.rows.length, 2);
    assert.deepEqual(Array.from(sheet.rows[1].slice(3)), [
        60,
        60,
        58,
        2,
        58 / 60,
        "2024/01/02 12:00:00",
        "2024/01/02 11:30:00",
        "network",
    ]);
    assert.equal(properties.getProperty("HEALTH_SUCCESS_COUNT"), "0");
    assert.equal(properties.getProperty("HEALTH_FAILURE_COUNT"), "0");
    assert.equal(properties.getProperty("HEALTH_PERIOD_START_TIME"), String(Date.parse("2024-01-02T03:04:05Z")));
});
