// GAS API をスタブして src/*.gs を Node で実行するテストランナー
// 実行: node tests/run-tests.js

const fs = require("fs");
const path = require("path");
const assert = require("node:assert/strict");

const srcDir = path.join(__dirname, "..", "src");
const files = fs
    .readdirSync(srcDir)
    .filter((file) => file.endsWith(".gs"))
    .sort();

const source = files
    .map((file) => fs.readFileSync(path.join(srcDir, file), "utf8"))
    .join("\n");

// ---------------------------------------------------------------- Utilities
function jstParts(date) {
    const formatter = new Intl.DateTimeFormat("en-CA", {
        timeZone: "Asia/Tokyo",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
        hour12: false,
    });
    const result = {};

    for (const part of formatter.formatToParts(date)) {
        if (part.type !== "literal") {
            result[part.type] = part.value;
        }
    }

    if (result.hour === "24") {
        result.hour = "00";
    }

    return result;
}

const Utilities = {
    DigestAlgorithm: { SHA_256: "SHA_256" },
    formatDate(date, timeZone, format) {
        const p = jstParts(date);

        if (format === "yyyy/MM/dd HH:mm:ss") {
            return `${p.year}/${p.month}/${p.day} ${p.hour}:${p.minute}:${p.second}`;
        }
        if (format === "yyyy/MM/dd HH:mm") {
            return `${p.year}/${p.month}/${p.day} ${p.hour}:${p.minute}`;
        }
        if (format === "yyyy/MM/dd") {
            return `${p.year}/${p.month}/${p.day}`;
        }
        if (format === "yyyyMMdd-HHmmss") {
            return `${p.year}${p.month}${p.day}-${p.hour}${p.minute}${p.second}`;
        }
        if (format === "H") {
            return String(Number(p.hour));
        }

        throw new Error("unsupported format: " + format);
    },
    parseDate(text) {
        const match = String(text).match(
            /^(\d{4})\/(\d{2})\/(\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?$/
        );

        if (!match) {
            throw new Error("unsupported date: " + text);
        }

        return new Date(
            `${match[1]}-${match[2]}-${match[3]}T${match[4]}:${match[5]}:${
                match[6] || "00"
            }+09:00`
        );
    },
    base64EncodeWebSafe(value) {
        return Buffer.from(String(value), "utf8").toString("base64url");
    },
    computeRsaSha256Signature() {
        return [1, 2, 3, 4];
    },
    computeDigest(algorithm, value) {
        return Array.from(
            require("crypto")
                .createHash("sha256")
                .update(String(value), "utf8")
                .digest()
        ).map((byte) => (byte > 127 ? byte - 256 : byte));
    },
};

// ------------------------------------------------------------ Script world
const world = {
    properties: {},
    sheets: {},
    driveFiles: new Map(),
    forms: {},
    copiedFormNames: [],
    sentMessages: [],
    sentRooms: [],
    requestedUrls: [],
    triggers: [],
    earthquakeResponse: [],
    earthquakeStatus: 200,
    lockAvailable: true,
    tokenRequests: 0,
};

const properties = {
    getProperty: (key) =>
        Object.prototype.hasOwnProperty.call(world.properties, key)
            ? world.properties[key]
            : null,
    setProperty: (key, value) => {
        world.properties[key] = String(value);
    },
    deleteProperty: (key) => {
        delete world.properties[key];
    },
};

const PropertiesService = {
    getScriptProperties: () => properties,
};

function makeSheet(name, headers) {
    const data = headers ? [headers.slice()] : [];

    return {
        name,
        data,
        getLastRow: () => data.length,
        getLastColumn: () =>
            data.reduce((max, row) => Math.max(max, row.length), 0),
        appendRow(row) {
            data.push(row.slice());
        },
        getRange(row, col, numRows, numCols) {
            return {
                getValues() {
                    const out = [];

                    for (let r = 0; r < numRows; r++) {
                        const line = [];

                        for (let c = 0; c < numCols; c++) {
                            const source = data[row - 1 + r];
                            line.push(source ? source[col - 1 + c] : "");
                        }

                        out.push(line);
                    }

                    return out;
                },
                setValues(values) {
                    for (let r = 0; r < values.length; r++) {
                        if (!data[row - 1 + r]) {
                            data[row - 1 + r] = [];
                        }

                        for (let c = 0; c < values[r].length; c++) {
                            data[row - 1 + r][col - 1 + c] = values[r][c];
                        }
                    }
                },
                clearContent() {
                    for (let r = 0; r < numRows; r++) {
                        for (let c = 0; c < numCols; c++) {
                            if (data[row - 1 + r]) {
                                data[row - 1 + r][col - 1 + c] = "";
                            }
                        }
                    }
                },
                setFontWeight() {},
                setNumberFormat() {},
            };
        },
        setFrozenRows() {},
    };
}

const spreadsheet = {
    getSheetByName: (name) => world.sheets[name] || null,
    insertSheet(name) {
        const sheet = makeSheet(name);
        world.sheets[name] = sheet;
        return sheet;
    },
};

const SpreadsheetApp = {
    openById: () => spreadsheet,
    getActiveSpreadsheet: () => spreadsheet,
};

function registerDriveFile(id, name, mimeType) {
    world.driveFiles.set(id, {
        id,
        name,
        mimeType,
        created: new Date(),
    });
}

function makeDriveFile(id, name, mimeType) {
    return {
        getId: () => id,
        getName: () => name,
        getMimeType: () => mimeType,
        getDateCreated: () => world.driveFiles.get(id).created,
        makeCopy(copyName) {
            const newId = "copy-" + (world.copiedFormNames.length + 1);
            world.copiedFormNames.push(copyName);
            registerDriveFile(newId, copyName, mimeType);
            return makeDriveFile(newId, copyName, mimeType);
        },
    };
}

const DriveApp = {
    getFileById(id) {
        const file = world.driveFiles.get(id);

        if (!file) {
            throw new Error("file not found: " + id);
        }

        return makeDriveFile(file.id, file.name, file.mimeType);
    },
    getFilesByName(name) {
        const matches = [...world.driveFiles.values()].filter(
            (file) => file.name === name
        );
        let index = 0;

        return {
            hasNext: () => index < matches.length,
            next() {
                const file = matches[index];
                index++;
                return makeDriveFile(file.id, file.name, file.mimeType);
            },
        };
    },
};

const MimeType = {
    GOOGLE_FORMS: "application/vnd.google-apps.form",
};

function makeForm(id) {
    const state = world.forms[id] || {};

    return {
        supportsAdvancedResponderPermissions: () => true,
        collectsEmail: () => false,
        isPublished: () => !!state.published,
        setPublished(value) {
            state.published = value;
            world.forms[id] = state;
        },
        isAcceptingResponses: () => !!state.accepting,
        setAcceptingResponses(value) {
            state.accepting = value;
            world.forms[id] = state;
        },
        getPublishedUrl: () => "https://forms.example/" + id,
    };
}

const FormApp = {
    openById: (id) => makeForm(id),
};

const UrlFetchApp = {
    fetch(url, options) {
        world.requestedUrls.push(url);

        // トークンURLは設定で変更できるため、URLの形で判別する
        if (url.indexOf("auth.worksmobile.com") !== -1 || url.indexOf("token") !== -1) {
            world.tokenRequests++;
            return {
                getResponseCode: () => 200,
                getContentText: () =>
                    JSON.stringify({ access_token: "token-1" }),
            };
        }

        if (url.indexOf("/channels/") !== -1) {
            const match = url.match(/\/channels\/([^/]+)\/messages/);
            world.sentRooms.push(match ? decodeURIComponent(match[1]) : "");
            world.sentMessages.push(JSON.parse(options.payload).content.text);
            return { getResponseCode: () => 204, getContentText: () => "" };
        }

        return {
            getResponseCode: () => world.earthquakeStatus,
            getContentText: () =>
                world.earthquakeStatus === 200
                    ? JSON.stringify(world.earthquakeResponse)
                    : "upstream error",
        };
    },
};

const LockService = {
    getScriptLock: () => ({
        tryLock: () => world.lockAvailable,
        releaseLock: () => {},
    }),
};

const ScriptApp = {
    getProjectTriggers: () => world.triggers,
    newTrigger: () => ({
        timeBased: () => ({
            everyMinutes: () => ({ create: () => {} }),
            everyHours: () => ({ create: () => {} }),
        }),
    }),
    deleteTrigger: () => {},
};

const gas = new Function(
    "Utilities",
    "console",
    "PropertiesService",
    "SpreadsheetApp",
    "FormApp",
    "DriveApp",
    "MimeType",
    "UrlFetchApp",
    "LockService",
    "ScriptApp",
    source +
        "\nreturn {" +
        [
            "runSelfTests",
            "loadConfig",
            "getExecutionContext",
            "loadState",
            "saveState",
            "createEmptyState",
            "checkAndNotifyEarthquake",
            "writeHourlyHealthCheck",
            "buildFormName",
            "buildP2pquakeUrl",
            "selectNotificationTargets",
            "connectivityCheck",
            "setupCheck",
            "fingerprintError",
        ].join(", ") +
        "};"
)(
    Utilities,
    {
        log: () => {},
        warn: () => {},
        error: () => {},
    },
    PropertiesService,
    SpreadsheetApp,
    FormApp,
    DriveApp,
    MimeType,
    UrlFetchApp,
    LockService,
    ScriptApp
);

// ------------------------------------------------------------- Test helpers
function resetWorld(options) {
    const opts = options || {};

    world.properties = {};
    world.sheets = {};
    world.driveFiles = new Map();
    world.forms = {};
    world.copiedFormNames = [];
    world.sentMessages = [];
    world.sentRooms = [];
    world.requestedUrls = [];
    world.triggers = [];
    world.earthquakeResponse = [];
    world.earthquakeStatus = 200;
    world.lockAvailable = true;
    world.tokenRequests = 0;

    if (!opts.withoutConfig) {
        world.properties.CLIENT_ID = "client";
        world.properties.CLIENT_SECRET = "secret";
        world.properties.SERVICE_ACCT = "service@example.com";
        world.properties.PRIVATE_KEY =
            "-----BEGIN PRIVATE KEY-----\nQUJDRA==\n-----END PRIVATE KEY-----";
        world.properties.BOT_ID = "bot";
        world.properties.ROOM_ID = "room";
        world.properties.NOTIFY_SCALE = "45";
        world.properties.FORM_TEMPLATE_ID = "template";
    }

    registerDriveFile("template", "安否確認テンプレート", MimeType.GOOGLE_FORMS);
}

function testEvent(time, hypocenterName, maxScale) {
    const earthquake = { time, maxScale };

    if (hypocenterName) {
        earthquake.hypocenter = { name: hypocenterName };
    }

    return {
        id: "event-" + time + "-" + (hypocenterName || "UNKNOWN"),
        earthquake,
    };
}

function daytimeEventTime() {
    // 静穏時間（既定0〜6時）に入らない固定の日中時刻を使う
    return Utilities.formatDate(new Date(), "Asia/Tokyo", "yyyy/MM/dd") + " 12:00:00";
}

function currentState() {
    const config = gas.loadConfig(properties);
    const context = gas.getExecutionContext(config, properties);
    return {
        config,
        context,
        state: gas.loadState(context),
    };
}

const tests = [];

function test(name, fn) {
    tests.push({ name, fn });
}

// ------------------------------------------------------------------- Tests
test("runSelfTests (選別ロジック)", () => {
    gas.runSelfTests();
});

test("状態シートの往復と保持期間の整理", () => {
    resetWorld();
    const { config, context } = currentState();
    const state = gas.createEmptyState();
    const now = new Date().toISOString();

    state.notified["2026/11/23 12:00:00|東京湾"] = {
        earthquakeTime: "2026/11/23 12:00:00",
        notifiedAt: now,
    };
    state.notifiedByTime["2026/11/23 12:00:00"] = {
        dedupeKey: "2026/11/23 12:00:00|東京湾",
        notifiedAt: now,
    };
    state.pendingUnknown["2026/11/23 13:00:00"] = {
        earthquakeTime: "2026/11/23 13:00:00",
        firstSeenAt: now,
    };
    state.forms["2026/11/23 12:00:00"] = {
        formId: "copy-1",
        responderUrl: "https://forms.example/copy-1",
        createdAt: now,
    };
    state.inflight["2026/11/23 14:00:00|東京湾"] = {
        earthquakeTime: "2026/11/23 14:00:00",
        startedAt: now,
    };
    state.quietBatch = {
        windowStart: "2026-11-22T15:00:00.000Z",
        windowEnd: "2026-11-22T21:00:00.000Z",
        candidate: { earthquakeTime: "2026/11/23 03:00:00" },
    };
    state.quietCompletedThrough = "2026-11-22T21:00:00.000Z";

    gas.saveState(context, state);
    const loaded = gas.loadState(context);

    assert.equal(Object.keys(loaded.notified).length, 1);
    assert.equal(Object.keys(loaded.notifiedByTime).length, 1);
    assert.equal(Object.keys(loaded.pendingUnknown).length, 1);
    assert.equal(Object.keys(loaded.forms).length, 1);
    assert.equal(Object.keys(loaded.inflight).length, 1);
    assert.equal(loaded.quietBatch.windowEnd, "2026-11-22T21:00:00.000Z");
    assert.equal(loaded.quietCompletedThrough, "2026-11-22T21:00:00.000Z");

    const old = gas.createEmptyState();
    old.notified["old"] = {
        earthquakeTime: "2020/01/01 00:00:00",
        notifiedAt: "2020-01-01T00:00:00.000Z",
    };
    old.notifiedByTime["2020/01/01 00:00:00"] = {
        dedupeKey: "old",
        notifiedAt: "2020-01-01T00:00:00.000Z",
    };
    old.forms["2020/01/01 00:00:00"] = {
        formId: "old-form",
        createdAt: "2020-01-01T00:00:00.000Z",
    };
    old.inflight["old"] = { startedAt: "2020-01-01T00:00:00.000Z" };

    gas.saveState(context, old);
    const reloaded = gas.loadState(context);

    assert.equal(Object.keys(reloaded.notified).length, 0);
    assert.equal(Object.keys(reloaded.notifiedByTime).length, 0);
    assert.equal(Object.keys(reloaded.forms).length, 0);
    assert.equal(Object.keys(reloaded.inflight).length, 0);
});

test("フォーム履歴の上限は通知済みと同じ件数", () => {
    resetWorld();
    const context = {
        stateSheet: makeSheet("状態"),
        config: {
            stateRetentionDays: 30,
            maxNotifiedRecords: 2,
        },
    };
    const state = gas.createEmptyState();

    for (let i = 0; i < 4; i++) {
        state.forms["time-" + i] = {
            formId: "form-" + i,
            createdAt: new Date(Date.now() - (4 - i) * 1000).toISOString(),
        };
    }

    gas.saveState(context, state);

    assert.equal(Object.keys(state.forms).length, 2);
    assert.ok(state.forms["time-3"]);
    assert.ok(state.forms["time-2"]);
});

test("メイン処理: フォーム複製・送信・状態保存・再実行で再送なし", () => {
    resetWorld();
    const eventTime = daytimeEventTime();
    world.earthquakeResponse = [testEvent(eventTime, "東京湾", 45)];

    gas.checkAndNotifyEarthquake();

    assert.equal(world.sentMessages.length, 1);
    assert.ok(
        world.sentMessages[0].indexOf("https://forms.example/copy-1") !== -1
    );
    assert.equal(world.copiedFormNames.length, 1);
    assert.ok(world.forms["copy-1"].published);
    assert.ok(world.forms["copy-1"].accepting);

    const { state } = currentState();
    assert.ok(state.notified[eventTime + "|東京湾"]);
    assert.ok(state.notifiedByTime[eventTime]);
    assert.ok(state.forms[eventTime].responderUrl);
    assert.equal(Object.keys(state.inflight).length, 0);

    const successRow = world.sheets["地震ログ"].data.find(
        (row) => row[5] === "送信成功"
    );
    assert.ok(successRow);
    assert.ok(String(successRow[6]).indexOf("attempt=1") !== -1);

    gas.checkAndNotifyEarthquake();
    assert.equal(world.sentMessages.length, 1);
    assert.equal(world.copiedFormNames.length, 1);
});

test("コピー直後に中断しても、同じ名前のフォームを再利用する", () => {
    resetWorld();
    const eventTime = daytimeEventTime();
    world.earthquakeResponse = [testEvent(eventTime, "東京湾", 45)];

    const { config } = currentState();
    const formName = gas.buildFormName(eventTime, config);
    registerDriveFile("orphan-form", formName, MimeType.GOOGLE_FORMS);

    gas.checkAndNotifyEarthquake();

    assert.equal(world.copiedFormNames.length, 0);
    assert.equal(world.sentMessages.length, 1);
    assert.ok(
        world.sentMessages[0].indexOf("https://forms.example/orphan-form") !== -1
    );

    const { state } = currentState();
    assert.equal(state.forms[eventTime].formId, "orphan-form");
});

test("送信結果不明のマークがある場合は再送し、attemptを増やす", () => {
    resetWorld();
    const eventTime = daytimeEventTime();
    world.earthquakeResponse = [testEvent(eventTime, "東京湾", 45)];

    const { context, state } = currentState();
    state.inflight[eventTime + "|東京湾"] = {
        dedupeKey: eventTime + "|東京湾",
        earthquakeTime: eventTime,
        hypocenterName: "東京湾",
        maxScale: 45,
        attempts: 1,
        startedAt: new Date().toISOString(),
    };
    gas.saveState(context, state);

    gas.checkAndNotifyEarthquake();

    assert.equal(world.sentMessages.length, 1);
    const { state: after } = currentState();
    assert.equal(Object.keys(after.inflight).length, 0);
    assert.ok(after.notified[eventTime + "|東京湾"]);

    const successRow = world.sheets["地震ログ"].data.find(
        (row) => row[5] === "送信成功"
    );
    assert.ok(String(successRow[6]).indexOf("attempt=2") !== -1);
});

test("旧4列ログの見出しを7列へ更新する", () => {
    resetWorld();
    world.sheets["地震ログ"] = makeSheet("地震ログ", [
        "記録時刻",
        "イベントID",
        "最大震度",
        "結果",
    ]);
    world.earthquakeResponse = [testEvent(daytimeEventTime(), "東京湾", 45)];

    gas.checkAndNotifyEarthquake();

    const header = world.sheets["地震ログ"].data[0];
    assert.equal(header.length, 7);
    assert.equal(header[6], "詳細");
});

test("API失敗時は失敗通知・エラーログ・失敗カウントを残す", () => {
    resetWorld();
    world.earthquakeStatus = 500;

    assert.throws(() => gas.checkAndNotifyEarthquake());
    assert.equal(world.properties.HEALTH_FAILURE_COUNT, "1");
    assert.equal(world.sentMessages.length, 1);

    const errorRow = world.sheets["地震ログ"].data.find(
        (row) => row[5] === "エラー"
    );
    assert.ok(errorRow);
    assert.ok(String(errorRow[6]).indexOf("地震API取得失敗") !== -1);
});

test("ヘルスチェック: 正常動作率は100%を超えず、カウンタをリセットする", () => {
    resetWorld();
    world.properties.HEALTH_SUCCESS_COUNT = "120";
    world.properties.HEALTH_FAILURE_COUNT = "0";
    world.properties.HEALTH_PERIOD_START_TIME = String(Date.now() - 3600000);

    gas.writeHourlyHealthCheck();

    const rows = world.sheets["ヘルスチェック"].data;
    const last = rows[rows.length - 1];

    assert.equal(rows.length, 2);
    assert.equal(last.length, 11);
    assert.equal(last[3], 60);
    assert.equal(last[5], 120);
    assert.equal(last[7], 1);
    assert.equal(world.properties.HEALTH_SUCCESS_COUNT, "0");
});

test("ヘルスチェック: ロックが取れない場合は記録せずカウンタを保持する", () => {
    resetWorld();
    world.lockAvailable = false;
    world.properties.HEALTH_SUCCESS_COUNT = "10";

    gas.writeHourlyHealthCheck();

    assert.equal(world.sheets["ヘルスチェック"], undefined);
    assert.equal(world.properties.HEALTH_SUCCESS_COUNT, "10");
});

test("設定: NOTIFY_SCALE 未設定はエラーにする", () => {
    resetWorld();
    delete world.properties.NOTIFY_SCALE;

    assert.throws(() => gas.loadConfig(properties), /NOTIFY_SCALE/);
});

test("設定: プレースホルダーが残っていたらエラーにする", () => {
    resetWorld();
    world.properties.CLIENT_ID = "REPLACE_WITH_CLIENT_ID";

    assert.throws(() => gas.loadConfig(properties), /プレースホルダー/);
});

test("設定: 固定フォームURLモードではテンプレート不要", () => {
    resetWorld();
    delete world.properties.FORM_TEMPLATE_ID;
    world.properties.FIXED_FORM_URL = "https://example.invalid/fixed-form";
    world.earthquakeResponse = [testEvent(daytimeEventTime(), "東京湾", 45)];

    gas.checkAndNotifyEarthquake();

    assert.equal(world.copiedFormNames.length, 0);
    assert.equal(world.sentMessages.length, 1);
    assert.ok(
        world.sentMessages[0].indexOf("https://example.invalid/fixed-form") !== -1
    );

    const { state } = currentState();
    assert.equal(Object.keys(state.forms).length, 0);
});

test("設定: API URLとトークンURLを上書きできる", () => {
    resetWorld();
    world.properties.P2PQUAKE_API_URL =
        "https://api.example.test/v2/jma/quake?limit=1";
    world.properties.LINEWORKS_TOKEN_URL = "https://auth.example.test/token";
    world.earthquakeResponse = [testEvent(daytimeEventTime(), "東京湾", 45)];

    const config = gas.loadConfig(properties);
    assert.equal(
        gas.buildP2pquakeUrl(config),
        "https://api.example.test/v2/jma/quake?limit=10"
    );

    gas.checkAndNotifyEarthquake();

    assert.ok(
        world.requestedUrls.indexOf("https://auth.example.test/token") !== -1
    );
});

test("疎通確認: 通常通知先と保守通知先の両方へ送る", () => {
    resetWorld();
    world.properties.MAINTENANCE_ROOM_ID = "maintenance";

    gas.connectivityCheck();

    assert.equal(world.sentMessages.length, 2);
    assert.equal(world.sentRooms[0], "room");
    assert.equal(world.sentRooms[1], "maintenance");
    assert.ok(world.sentMessages[0].indexOf("通知用チャット疎通確認") !== -1);
    assert.ok(world.sentMessages[1].indexOf("保守通知用チャット疎通確認") !== -1);

    // 保守通知先が未設定なら通常通知先の1回だけ
    resetWorld();
    gas.connectivityCheck();
    assert.equal(world.sentMessages.length, 1);
    assert.equal(world.sentRooms[0], "room");
});

test("障害通知: fingerprintに例外名を含める", () => {
    const first = gas.fingerprintError("check_failed", {
        name: "TypeError",
        message: "同じメッセージ",
    });
    const second = gas.fingerprintError("check_failed", {
        name: "RangeError",
        message: "同じメッセージ",
    });

    assert.notEqual(first, second);
    assert.equal(first.length, 64);
});

test("セットアップ確認: トリガー間隔の記録がないとNG", () => {
    resetWorld();
    world.triggers = [
        { getHandlerFunction: () => "checkAndNotifyEarthquake" },
        { getHandlerFunction: () => "writeHourlyHealthCheck" },
    ];

    assert.equal(gas.setupCheck(), false);

    world.properties.TRIGGER_CHECK_INTERVAL_MINUTES = "1";
    world.properties.TRIGGER_HEALTH_INTERVAL_HOURS = "1";

    assert.equal(gas.setupCheck(), true);
});

// ---------------------------------------------------------------- Runner
let failed = 0;

for (const { name, fn } of tests) {
    try {
        fn();
        console.log("OK   " + name);
    } catch (e) {
        failed++;
        console.error("FAIL " + name);
        console.error("     " + (e && e.stack ? e.stack : e));
    }
}

console.log("");
console.log(`${tests.length - failed}/${tests.length} passed`);

if (failed > 0) {
    process.exitCode = 1;
}
