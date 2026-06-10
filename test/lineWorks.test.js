const assert = require("node:assert/strict");
const test = require("node:test");
const { MockProperties, MockResponse, createRuntime } = require("./helpers/gasRuntime");

test("sendLineWorksMessage posts text payload to LINE WORKS", () => {
    const properties = new MockProperties({ BOT_ID: "bot-1" });
    const gas = createRuntime({
        properties,
        fetchQueue: [new MockResponse(201, "{}")],
    });

    gas.sendLineWorksMessage("token-1", properties, "room-1", "hello");

    assert.equal(gas.fetchCalls.length, 1);
    assert.equal(gas.fetchCalls[0].url, "https://www.worksapis.com/v1.0/bots/bot-1/channels/room-1/messages");
    assert.equal(gas.fetchCalls[0].params.method, "post");
    assert.equal(gas.fetchCalls[0].params.headers.Authorization, "Bearer token-1");
    assert.deepEqual(JSON.parse(gas.fetchCalls[0].params.payload), {
        content: {
            type: "text",
            text: "hello",
        },
    });
});

test("getAccessToken requires LINE WORKS credentials", () => {
    const gas = createRuntime();

    assert.throws(() => gas.getAccessToken(gas.properties), /CLIENT_ID未設定/);
});

test("getAccessToken returns access token from token endpoint", () => {
    const properties = new MockProperties({
        CLIENT_ID: "client-1",
        CLIENT_SECRET: "secret-1",
        SERVICE_ACCT: "service-1",
        PRIVATE_KEY: "-----BEGIN PRIVATE KEY-----abc-----END PRIVATE KEY-----",
    });
    const gas = createRuntime({
        properties,
        fetchQueue: [new MockResponse(200, JSON.stringify({ access_token: "access-1" }))],
    });

    assert.equal(gas.getAccessToken(properties), "access-1");
    assert.equal(gas.fetchCalls[0].url, "https://auth.worksmobile.com/oauth2/v2.0/token");
    assert.equal(gas.fetchCalls[0].params.payload.client_id, "client-1");
    assert.equal(gas.fetchCalls[0].params.payload.scope, "bot.message");
});
