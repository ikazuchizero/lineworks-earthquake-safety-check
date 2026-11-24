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
    sleep() {},
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
    failFormIds: new Set(),
    failFormOnceIds: new Set(),
    copiedFormNames: [],
    sentMessages: [],
    sentRooms: [],
    requestedUrls: [],
    triggers: [],
    sheetWrites: [],
    earthquakeResponse: [],
    earthquakeStatus: 200,
    sendStatus: 204,
    lockAvailable: true,
    userLockAvailable: true,
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
        deleteRows(startRow, numRows) {
            world.sheetWrites.push({ name, op: "deleteRows", startRow, numRows });
            data.splice(startRow - 1, numRows);
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
                    world.sheetWrites.push({ name, op: "setValues", row, col });
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
                    world.sheetWrites.push({ name, op: "clearContent", row, col });
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
    if (world.failFormIds.has(id)) {
        throw new Error("form not found: " + id);
    }

    if (world.failFormOnceIds.has(id)) {
        world.failFormOnceIds.delete(id);
        throw new Error("temporary form error: " + id);
    }

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
    openByUrl: (url) => makeForm("url:" + url),
};

const UrlFetchApp = {
    fetch(url, options) {
        world.requestedUrls.push(url);

        // トークンURLは設定で変更できるため、URLの形で判別する
        if (url.indexOf("auth.worksmobile.com") !== -1 || url.indexOf("token") !== -1) {
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
            return {
                getResponseCode: () => world.sendStatus,
                getContentText: () =>
                    world.sendStatus >= 200 && world.sendStatus < 300
                        ? ""
                        : "send failed",
            };
        }

        const status =
            Array.isArray(world.earthquakeStatuses) &&
            world.earthquakeStatuses.length > 0
                ? world.earthquakeStatuses.shift()
                : world.earthquakeStatus;

        return {
            getResponseCode: () => status,
            getContentText: () =>
                status === 200
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
    getUserLock: () => ({
        tryLock: () => world.userLockAvailable,
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
            "initializeNotificationBaseline",
            "cleanupLogs",
            "cleanupLogsSheet",
            "isStaleEarthquakeTime",
            "recordExecutionFailure",
            "recordExecutionSkipped",
            "CHECK_INTERVAL_MINUTES",
            "createEarthquakeMessage",
            "fetchEarthquakes",
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
function resetWorld() {
    world.properties = {};
    world.sheets = {};
    world.driveFiles = new Map();
    world.forms = {};
    world.failFormIds = new Set();
    world.failFormOnceIds = new Set();
    world.copiedFormNames = [];
    world.sentMessages = [];
    world.sentRooms = [];
    world.requestedUrls = [];
    world.triggers = [];
    world.sheetWrites = [];
    world.earthquakeResponse = [];
    world.earthquakeStatus = 200;
    world.earthquakeStatuses = [];
    world.sendStatus = 204;
    world.lockAvailable = true;
    world.userLockAvailable = true;

    world.properties.CLIENT_ID = "client";
    world.properties.CLIENT_SECRET = "secret";
    world.properties.SERVICE_ACCT = "service@example.com";
    world.properties.PRIVATE_KEY =
        "-----BEGIN PRIVATE KEY-----\nQUJDRA==\n-----END PRIVATE KEY-----";
    world.properties.BOT_ID = "bot";
    world.properties.ROOM_ID = "room";
    world.properties.NOTIFY_SCALE = "45";
    world.properties.FORM_TEMPLATE_ID = "template";

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
    const recentQuietTime = Utilities.formatDate(
        new Date(Date.now() - 60 * 60 * 1000),
        "Asia/Tokyo",
        "yyyy/MM/dd HH:mm:ss"
    );

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
        candidate: { earthquakeTime: recentQuietTime },
    };
    state.quietRelease = {
        windowStart: "2026-11-22T15:00:00.000Z",
        windowEnd: "2026-11-22T21:00:00.000Z",
        candidate: { earthquakeTime: recentQuietTime },
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
    assert.equal(loaded.quietRelease.windowEnd, "2026-11-22T21:00:00.000Z");
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
    old.pendingUnknown["2020/01/01 01:00:00"] = {
        earthquakeTime: "2020/01/01 01:00:00",
        firstSeenAt: "2020-01-01T01:00:00.000Z",
    };
    old.quietBatch = {
        windowStart: "2019-12-31T15:00:00.000Z",
        windowEnd: "2019-12-31T21:00:00.000Z",
        candidate: { earthquakeTime: "2020/01/01 03:00:00" },
    };
    old.quietRelease = {
        windowStart: "2020-01-01T15:00:00.000Z",
        windowEnd: "2020-01-01T21:00:00.000Z",
        candidate: { earthquakeTime: "2020/01/02 03:00:00" },
    };

    gas.saveState(context, old);
    const reloaded = gas.loadState(context);

    assert.equal(Object.keys(reloaded.notified).length, 0);
    assert.equal(Object.keys(reloaded.notifiedByTime).length, 0);
    assert.equal(Object.keys(reloaded.forms).length, 0);
    assert.equal(Object.keys(reloaded.inflight).length, 0);
    assert.equal(Object.keys(reloaded.pendingUnknown).length, 0);
    assert.equal(reloaded.quietBatch, null);
    assert.equal(reloaded.quietRelease, null);
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

test("地震API: 429のとき再試行して成功する", () => {
    resetWorld();
    world.earthquakeStatuses = [429, 200];
    world.earthquakeResponse = [testEvent(daytimeEventTime(), "東京湾", 45)];

    const config = gas.loadConfig(properties);
    const events = gas.fetchEarthquakes(config);

    assert.equal(events.length, 1);
    assert.equal(world.requestedUrls.length, 2);
});

test("地震API: 4xxは再試行しない", () => {
    resetWorld();
    world.earthquakeStatuses = [400, 200];

    const config = gas.loadConfig(properties);
    assert.throws(() => gas.fetchEarthquakes(config));
    assert.equal(world.requestedUrls.length, 1);
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

test("ヘルスチェック: 正常動作率は100%を超えず、実行成功率とスキップ数を記録する", () => {
    resetWorld();
    world.properties.HEALTH_SUCCESS_COUNT = "120";
    world.properties.HEALTH_FAILURE_COUNT = "0";
    world.properties.HEALTH_SKIPPED_COUNT = "3";
    world.properties.HEALTH_PERIOD_START_TIME = String(Date.now() - 3600000);

    gas.writeHourlyHealthCheck();

    const rows = world.sheets["ヘルスチェック"].data;
    const last = rows[rows.length - 1];

    assert.equal(rows.length, 2);
    assert.equal(last.length, 13);
    assert.equal(last[3], Math.round(60 / gas.CHECK_INTERVAL_MINUTES));
    assert.equal(last[5], 120);
    assert.equal(last[7], 1);
    assert.equal(last[11], 3);
    assert.equal(last[12], 1);
    assert.equal(world.properties.HEALTH_SUCCESS_COUNT, "0");
    assert.equal(world.properties.HEALTH_SKIPPED_COUNT, "0");
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

    world.properties.TRIGGER_CHECK_INTERVAL_MINUTES = String(gas.CHECK_INTERVAL_MINUTES);
    world.properties.TRIGGER_HEALTH_INTERVAL_HOURS = "1";

    assert.equal(gas.setupCheck(), true);
});

test("メイン処理: 変化がない実行では状態シートを書き換えない", () => {
    resetWorld();

    gas.checkAndNotifyEarthquake();

    const stateWrites = world.sheetWrites.filter(
        (write) =>
            write.name === "状態" &&
            (write.op === "setValues" || write.op === "clearContent")
    );

    assert.equal(world.sentMessages.length, 0);
    assert.equal(stateWrites.length, 0);
});

test("初回ベースライン: 過去の地震を送信せず通知済みにする", () => {
    resetWorld();
    const eventTime = daytimeEventTime();
    world.earthquakeResponse = [testEvent(eventTime, "東京湾", 45)];

    const count = gas.initializeNotificationBaseline();

    assert.equal(count, 1);
    assert.equal(world.sentMessages.length, 0);
    assert.equal(world.copiedFormNames.length, 0);

    const { state } = currentState();
    assert.ok(state.notified[eventTime + "|東京湾"]);
    assert.ok(state.notifiedByTime[eventTime]);

    // ベースライン後の再実行でも送信しない
    gas.checkAndNotifyEarthquake();
    assert.equal(world.sentMessages.length, 0);
});

test("古い静穏バッチと保留UNKNOWNは通知せず破棄する", () => {
    resetWorld();
    const config = gas.loadConfig(properties);
    const context = gas.getExecutionContext(config, properties);
    const state = gas.createEmptyState();

    state.quietBatch = {
        windowStart: "2019-12-31T15:00:00.000Z",
        windowEnd: "2019-12-31T21:00:00.000Z",
        candidate: {
            earthquakeTime: "2020/01/01 03:00:00",
            hypocenterName: "東京湾",
            maxScale: 45,
            dedupeKey: "2020/01/01 03:00:00|東京湾",
        },
    };
    state.quietRelease = {
        windowStart: "2020-01-01T15:00:00.000Z",
        windowEnd: "2020-01-01T21:00:00.000Z",
        candidate: {
            earthquakeTime: "2020/01/02 03:00:00",
            hypocenterName: "東京湾",
            maxScale: 45,
            dedupeKey: "2020/01/02 03:00:00|東京湾",
        },
    };
    state.pendingUnknown["2020/01/01 01:00:00"] = {
        earthquakeTime: "2020/01/01 01:00:00",
        hypocenterName: "UNKNOWN",
        maxScale: 45,
        firstSeenAt: "2020-01-01T01:00:00.000Z",
    };

    const targets = gas.selectNotificationTargets(
        [],
        state,
        config,
        new Date(),
        context
    );

    assert.equal(targets.length, 0);
    assert.equal(state.quietBatch, null);
    assert.equal(state.quietRelease, null);
    assert.equal(Object.keys(state.pendingUnknown).length, 0);
});

test("静穏時間は QUIET_HOURS_ENABLED=false で無効にできる", () => {
    resetWorld();
    world.properties.QUIET_HOURS_ENABLED = "false";
    const eventTime =
        Utilities.formatDate(new Date(), "Asia/Tokyo", "yyyy/MM/dd") + " 03:00:00";
    world.earthquakeResponse = [testEvent(eventTime, "東京湾", 45)];

    gas.checkAndNotifyEarthquake();

    assert.equal(world.sentMessages.length, 1);
    const { state } = currentState();
    assert.equal(state.quietBatch, null);
    assert.equal(state.quietRelease, null);
});

test("静穏バッチ解放後、同じ実行で次の時間帯を取り込める", () => {
    resetWorld();
    world.properties.MAX_NOTIFICATION_AGE_HOURS = "168";
    const config = gas.loadConfig(properties);
    const context = gas.getExecutionContext(config, properties);
    const state = gas.createEmptyState();
    const today = Utilities.formatDate(new Date(), "Asia/Tokyo", "yyyy/MM/dd");
    const oldDay = Utilities.formatDate(
        new Date(Date.now() - 2 * 24 * 60 * 60 * 1000),
        "Asia/Tokyo",
        "yyyy/MM/dd"
    );

    state.quietBatch = {
        windowStart: new Date(Date.now() - 2 * 24 * 60 * 60 * 1000).toISOString(),
        windowEnd: new Date(Date.now() - 2 * 24 * 60 * 60 * 1000 + 3600000).toISOString(),
        candidate: {
            earthquakeTime: oldDay + " 03:00:00",
            hypocenterName: "東京湾",
            maxScale: 45,
            dedupeKey: oldDay + " 03:00:00|東京湾",
        },
    };

    const newEventTime = today + " 05:50:00";
    const targets = gas.selectNotificationTargets(
        [testEvent(newEventTime, "茨城県沖", 50)],
        state,
        config,
        new Date(),
        context
    );

    assert.equal(targets.length, 1);
    assert.ok(state.quietRelease);
    assert.ok(state.quietBatch);
    assert.equal(state.quietBatch.candidate.earthquakeTime, newEventTime);
});

test("保存済みフォームが削除されていたら作り直す", () => {
    resetWorld();
    const eventTime = daytimeEventTime();
    world.earthquakeResponse = [testEvent(eventTime, "東京湾", 45)];

    const { context, state } = currentState();
    state.forms[eventTime] = {
        formId: "missing",
        responderUrl: "https://forms.example/missing",
        createdAt: new Date().toISOString(),
    };
    gas.saveState(context, state);
    world.failFormIds.add("missing");

    gas.checkAndNotifyEarthquake();

    assert.equal(world.sentMessages.length, 1);
    assert.ok(world.sentMessages[0].indexOf("copy-1") !== -1);
    assert.equal(world.copiedFormNames.length, 1);

    const { state: after } = currentState();
    assert.equal(after.forms[eventTime].formId, "copy-1");
});

test("セットアップ確認: 固定URLのGoogleフォームは回答受付を確認する", () => {
    resetWorld();
    delete world.properties.FORM_TEMPLATE_ID;
    const url = "https://docs.google.com/forms/d/test-form-id/edit";
    world.properties.FIXED_FORM_URL = url;
    world.triggers = [
        { getHandlerFunction: () => "checkAndNotifyEarthquake" },
        { getHandlerFunction: () => "writeHourlyHealthCheck" },
    ];
    world.properties.TRIGGER_CHECK_INTERVAL_MINUTES = String(gas.CHECK_INTERVAL_MINUTES);
    world.properties.TRIGGER_HEALTH_INTERVAL_HOURS = "1";
    world.forms["url:" + url] = { accepting: true, published: true };

    assert.equal(gas.setupCheck(), true);

    world.forms["url:" + url].accepting = false;
    assert.equal(gas.setupCheck(), false);
});

test("状態: シートがキーを日時として保存しても重複排除できる", () => {
    resetWorld();
    world.properties.NOTIFY_SCALE = "0";
    const config = gas.loadConfig(properties);
    const context = gas.getExecutionContext(config, properties);

    // Sheets が "2026/11/23 07:49:00" を日時として保存してしまった状態を再現する
    const rows = [
        ["種別", "キー", "値", "更新日時"],
        [
            "notified_time",
            Utilities.parseDate(
                "2026/11/23 07:49:00",
                "Asia/Tokyo",
                "yyyy/MM/dd HH:mm:ss"
            ),
            JSON.stringify({
                dedupeKey: "2026/11/23 07:49:00|天草灘",
                notifiedAt: "2026-11-23T14:14:05.596Z",
            }),
            "2026-11-23T14:50:12.145Z",
        ],
    ];
    context.stateSheet.data.length = 0;
    rows.forEach((row) => context.stateSheet.data.push(row));

    const state = gas.loadState(context);
    assert.ok(!!state.notifiedByTime["2026/11/23 07:49:00"]);

    // 同じ発生時刻の別報（UNKNOWN）を取得しても保留・通知しない
    const targets = gas.selectNotificationTargets(
        [testEvent("2026/11/23 07:49:00", "", 30)],
        state,
        config,
        Utilities.parseDate(
            "2026/11/23 23:40:00",
            "Asia/Tokyo",
            "yyyy/MM/dd HH:mm:ss"
        ),
        context
    );

    assert.equal(targets.length, 0);
    assert.equal(Object.keys(state.pendingUnknown).length, 0);
});

test("通常時間帯: 保留期限切れの回に続報が来たら震源地ありを通知する", () => {
    resetWorld();
    world.properties.NOTIFY_SCALE = "0";
    const config = gas.loadConfig(properties);
    const context = gas.getExecutionContext(config, properties);
    const state = gas.createEmptyState();
    const eventTime = "2026/11/23 07:49:00";
    const firstSeen = Utilities.parseDate(
        "2026/11/23 07:51:00",
        "Asia/Tokyo",
        "yyyy/MM/dd HH:mm:ss"
    );

    // 1回目: 第1報（震源不明）
    const firstTargets = gas.selectNotificationTargets(
        [testEvent(eventTime, "", 30)],
        state,
        config,
        firstSeen,
        context
    );

    assert.equal(firstTargets.length, 0);
    assert.ok(!!state.pendingUnknown[eventTime]);

    // 2回目: 10分後、同じ発生時刻の詳細報（震源地名あり）が届いている
    const secondTargets = gas.selectNotificationTargets(
        [testEvent(eventTime, "宮古島", 30), testEvent(eventTime, "", 30)],
        state,
        config,
        new Date(firstSeen.getTime() + 11 * 60 * 1000),
        context
    );

    assert.equal(secondTargets.length, 1);
    assert.equal(secondTargets[0].hypocenterName, "宮古島");
    assert.equal(secondTargets[0].maxScale, 30);
});

test("メッセージ: 震源不明はUNKNOWNと表示せず「調査中」にする", () => {
    const message = gas.createEarthquakeMessage(
        { earthquakeTime: "2026/11/23 07:49:00", hypocenterName: "UNKNOWN", maxScale: 30 },
        "https://example.com/form"
    );

    assert.ok(message.indexOf("UNKNOWN") === -1);
    assert.ok(message.indexOf("震源地：調査中") !== -1);
    assert.ok(message.indexOf("最大震度：3") !== -1);
    assert.ok(message.indexOf("https://example.com/form") !== -1);
});

test("障害記録: 長すぎるエラーメッセージを切り詰める", () => {
    resetWorld();

    gas.recordExecutionFailure(new Error("x".repeat(5000)));

    const stored = world.properties.HEALTH_LAST_ERROR_MESSAGE;

    assert.equal(stored.length, 1000);
    assert.ok(stored.endsWith("…"));
});

test("設定: https以外のURLは拒否する", () => {
    resetWorld();
    world.properties.P2PQUAKE_API_URL = "http://example.test/quake";

    assert.throws(() => gas.loadConfig(properties), /https/);
});

test("ヘルスチェック: ロック競合でスキップした回数を記録する", () => {
    resetWorld();
    world.lockAvailable = false;

    gas.checkAndNotifyEarthquake();

    assert.equal(world.properties.HEALTH_SKIPPED_COUNT, "1");
    assert.equal(world.sentMessages.length, 0);

    world.lockAvailable = true;
    gas.writeHourlyHealthCheck();

    const rows = world.sheets["ヘルスチェック"].data;
    const last = rows[rows.length - 1];
    assert.equal(last[11], 1);
});

test("ヘルスチェック: 保持期間を超えた地震ログを削除する", () => {
    resetWorld();
    const headers = [
        "記録時刻",
        "イベントID",
        "発生時刻",
        "震源地",
        "最大震度",
        "結果",
        "詳細",
    ];
    const sheet = makeSheet("地震ログ", headers);
    world.sheets["地震ログ"] = sheet;
    const oldDate = new Date(Date.now() - 100 * 24 * 60 * 60 * 1000);
    const newDate = new Date();
    sheet.data.push([oldDate, "-", "-", "-", "-", "エラー", "old"]);
    sheet.data.push([newDate, "-", "-", "-", "-", "エラー", "new"]);

    gas.writeHourlyHealthCheck();

    assert.equal(world.sheets["地震ログ"].data.length, 2);
    assert.equal(world.sheets["地震ログ"].data[0][0], "記録時刻");
    assert.equal(world.sheets["地震ログ"].data[1][6], "new");
});

test("静穏通知: 解放待ちの送信成功後、次の時間帯のバッチを残す", () => {
    resetWorld();
    const today = Utilities.formatDate(new Date(), "Asia/Tokyo", "yyyy/MM/dd");
    const tomorrow = Utilities.formatDate(
        new Date(Date.now() + 24 * 60 * 60 * 1000),
        "Asia/Tokyo",
        "yyyy/MM/dd"
    );
    const start = Utilities.parseDate(
        today + " 00:00:00",
        "Asia/Tokyo",
        "yyyy/MM/dd HH:mm:ss"
    );
    const end = new Date(start.getTime() + 6 * 60 * 60 * 1000);
    const eventTime = today + " 05:50:00";
    const nextEventTime = tomorrow + " 03:00:00";

    const { context, state } = currentState();
    state.quietRelease = {
        windowStart: start.toISOString(),
        windowEnd: end.toISOString(),
        candidate: {
            earthquakeTime: eventTime,
            hypocenterName: "東京湾",
            maxScale: 45,
            dedupeKey: eventTime + "|東京湾",
        },
    };
    state.quietBatch = {
        windowStart: new Date(start.getTime() + 24 * 60 * 60 * 1000).toISOString(),
        windowEnd: new Date(end.getTime() + 24 * 60 * 60 * 1000).toISOString(),
        candidate: {
            earthquakeTime: nextEventTime,
            hypocenterName: "茨城県沖",
            maxScale: 50,
            dedupeKey: nextEventTime + "|茨城県沖",
        },
    };
    gas.saveState(context, state);

    gas.checkAndNotifyEarthquake();

    assert.equal(world.sentMessages.length, 1);

    const { state: after } = currentState();
    assert.equal(after.quietRelease, null);
    assert.ok(after.quietBatch);
    assert.equal(after.quietBatch.candidate.earthquakeTime, nextEventTime);
    assert.equal(after.quietCompletedThrough, end.toISOString());
});

test("静穏通知: 送信失敗時は解放待ちを残して再試行する", () => {
    resetWorld();
    world.sendStatus = 500;
    const today = Utilities.formatDate(new Date(), "Asia/Tokyo", "yyyy/MM/dd");
    const start = Utilities.parseDate(
        today + " 00:00:00",
        "Asia/Tokyo",
        "yyyy/MM/dd HH:mm:ss"
    );
    const end = new Date(start.getTime() + 6 * 60 * 60 * 1000);
    const eventTime = today + " 05:50:00";

    const { context, state } = currentState();
    state.quietRelease = {
        windowStart: start.toISOString(),
        windowEnd: end.toISOString(),
        candidate: {
            earthquakeTime: eventTime,
            hypocenterName: "東京湾",
            maxScale: 45,
            dedupeKey: eventTime + "|東京湾",
        },
    };
    gas.saveState(context, state);

    assert.throws(() => gas.checkAndNotifyEarthquake());

    const { state: after } = currentState();
    assert.ok(after.quietRelease);
    assert.equal(after.quietRelease.candidate.dedupeKey, eventTime + "|東京湾");
    assert.equal(after.notified[eventTime + "|東京湾"], undefined);
});

test("ベースライン: 稼働中のトリガーがあると拒否し、フラグで許可する", () => {
    resetWorld();
    world.triggers = [
        { getHandlerFunction: () => "checkAndNotifyEarthquake" },
    ];

    assert.throws(() => gas.initializeNotificationBaseline(), /ベースライン/);
    assert.equal(world.sentMessages.length, 0);

    world.properties.ALLOW_BASELINE_WITH_TRIGGERS = "true";
    world.earthquakeResponse = [testEvent(daytimeEventTime(), "東京湾", 45)];

    assert.equal(gas.initializeNotificationBaseline(), 1);
});

test("ベースライン: 保留・静穏状態もクリアする", () => {
    resetWorld();
    const eventTime = daytimeEventTime();
    const recent = Utilities.formatDate(
        new Date(Date.now() - 60 * 60 * 1000),
        "Asia/Tokyo",
        "yyyy/MM/dd HH:mm:ss"
    );
    world.earthquakeResponse = [testEvent(eventTime, "東京湾", 45)];

    const { context, state } = currentState();
    state.pendingUnknown[recent] = {
        earthquakeTime: recent,
        hypocenterName: "UNKNOWN",
        maxScale: 45,
        firstSeenAt: new Date().toISOString(),
    };
    state.quietBatch = {
        windowStart: "2026-01-01T15:00:00.000Z",
        windowEnd: "2026-01-01T21:00:00.000Z",
        candidate: { earthquakeTime: recent },
    };
    state.quietRelease = {
        windowStart: "2026-01-01T15:00:00.000Z",
        windowEnd: "2026-01-01T21:00:00.000Z",
        candidate: { earthquakeTime: recent },
    };
    state.quietCompletedThrough = "2099-01-01T00:00:00.000Z";
    gas.saveState(context, state);

    gas.initializeNotificationBaseline();

    const { state: after } = currentState();
    assert.equal(Object.keys(after.pendingUnknown).length, 0);
    assert.equal(after.quietBatch, null);
    assert.equal(after.quietRelease, null);
    assert.equal(after.quietCompletedThrough, null);
});

test("静穏通知: 保留期限切れと同じ実行の続報は震源地ありを優先する", () => {
    resetWorld();
    const config = gas.loadConfig(properties);
    const context = gas.getExecutionContext(config, properties);
    const state = gas.createEmptyState();
    const today = Utilities.formatDate(new Date(), "Asia/Tokyo", "yyyy/MM/dd");
    const start = Utilities.parseDate(
        today + " 00:00:00",
        "Asia/Tokyo",
        "yyyy/MM/dd HH:mm:ss"
    );
    const end = new Date(start.getTime() + 6 * 60 * 60 * 1000);
    const eventTime = today + " 05:55:00";

    state.quietRelease = {
        windowStart: start.toISOString(),
        windowEnd: end.toISOString(),
        candidate: {
            earthquakeTime: eventTime,
            hypocenterName: "UNKNOWN",
            maxScale: 45,
            dedupeKey: eventTime + "|UNKNOWN",
        },
    };
    state.pendingUnknown[eventTime] = {
        earthquakeTime: eventTime,
        hypocenterName: "UNKNOWN",
        maxScale: 45,
        firstSeenAt: Utilities.parseDate(
            today + " 05:56:00",
            "Asia/Tokyo",
            "yyyy/MM/dd HH:mm:ss"
        ).toISOString(),
    };

    const targets = gas.selectNotificationTargets(
        [testEvent(eventTime, "東京湾", 50)],
        state,
        config,
        Utilities.parseDate(
            today + " 06:11:00",
            "Asia/Tokyo",
            "yyyy/MM/dd HH:mm:ss"
        ),
        context
    );

    assert.equal(targets.length, 1);
    assert.equal(targets[0].hypocenterName, "東京湾");
    assert.equal(state.quietRelease.candidate.hypocenterName, "東京湾");
});

test("設定: 保留時間は最大経過時間より短くなければならない", () => {
    resetWorld();
    world.properties.MAX_NOTIFICATION_AGE_HOURS = "1";
    world.properties.UNKNOWN_HYPOCENTER_HOLD_SECONDS = "3600";

    assert.throws(() => gas.loadConfig(properties), /UNKNOWN_HYPOCENTER_HOLD_SECONDS/);
});

test("フォーム: 一時的なオープン失敗は再試行し、複製しない", () => {
    resetWorld();
    const eventTime = daytimeEventTime();
    world.earthquakeResponse = [testEvent(eventTime, "東京湾", 45)];

    const { context, state } = currentState();
    state.forms[eventTime] = {
        formId: "temporary",
        responderUrl: "https://forms.example/temporary",
        createdAt: new Date().toISOString(),
    };
    gas.saveState(context, state);
    world.failFormOnceIds.add("temporary");

    gas.checkAndNotifyEarthquake();

    assert.equal(world.copiedFormNames.length, 0);
    assert.ok(world.sentMessages[0].indexOf("https://forms.example/temporary") !== -1);

    const { state: after } = currentState();
    assert.equal(after.forms[eventTime].formId, "temporary");
});

test("ヘルスチェック: カウンタ用ユーザーロックが取れない場合は記録しない", () => {
    resetWorld();
    world.userLockAvailable = false;
    world.properties.HEALTH_SUCCESS_COUNT = "10";

    gas.writeHourlyHealthCheck();

    const rows = world.sheets["ヘルスチェック"].data;
    assert.equal(rows.length, 1);
    assert.equal(world.properties.HEALTH_SUCCESS_COUNT, "10");
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
