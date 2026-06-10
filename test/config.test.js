const assert = require("node:assert/strict");
const test = require("node:test");
const { createRuntime } = require("./helpers/gasRuntime");

test("createHttpError includes message, status code, and response body", () => {
    const gas = createRuntime();
    const error = gas.createHttpError("API失敗", 500, "server error");

    assert.equal(error.message, "API失敗 HTTP:500 server error");
});
