const assert = require("node:assert/strict");
const test = require("node:test");
const { MockProperties, createRuntime } = require("./helpers/gasRuntime");

test("logResult throttles routine skip and duplicate logs", () => {
    const properties = new MockProperties();
    const gas = createRuntime({ properties, nowMs: 5000000 });
    const context = {
        properties,
        sheet: gas.logSheet,
        nowTime: new gas.context.Date(),
    };

    gas.logResult(context, "event-1", "1", "通知対象外");
    gas.logResult(context, "event-2", "1", "通知対象外");

    assert.equal(gas.logSheet.rows.length, 1);
    assert.equal(properties.getProperty("LAST_ROUTINE_LOG_TIME"), "5000000");
});
