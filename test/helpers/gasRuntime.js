const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const rootDir = path.resolve(__dirname, "../..");
const sourceFiles = [
    "src/config.gs",
    "src/logging.gs",
    "src/healthCheck.gs",
    "src/lineWorks.gs",
    "src/checkAndNotifyEarthquake.gs",
];
const exportNames = [
    "checkAndNotifyEarthquake",
    "getExecutionContext",
    "getLatestEarthquake",
    "processEarthquakeValidation",
    "isDuplicateEvent",
    "notifyEarthquake",
    "createEarthquakeMessage",
    "resetLastNotifiedId",
    "logResult",
    "shouldSkipLog",
    "writeLog",
    "processError",
    "recordExecutionSuccess",
    "recordExecutionFailure",
    "writeHourlyHealthCheck",
    "ensureHealthPeriodStarted",
    "incrementHealthCount",
    "getOrCreateHealthSheet",
    "resetHealthCounters",
    "formatHealthTime",
    "getAccessToken",
    "sendLineWorksMessage",
    "createHttpError",
    "ERROR_MESSAGES",
    "LOG_MESSAGES",
    "HEALTH_KEYS",
];

class MockProperties {
    constructor(initial = {}) {
        this.store = { ...initial };
    }

    getProperty(key) {
        return Object.prototype.hasOwnProperty.call(this.store, key) ? this.store[key] : null;
    }

    setProperty(key, value) {
        this.store[key] = String(value);
    }

    deleteProperty(key) {
        delete this.store[key];
    }
}

class MockSheet {
    constructor(name) {
        this.name = name;
        this.rows = [];
    }

    appendRow(row) {
        this.rows.push(row);
    }
}

class MockSpreadsheet {
    constructor(sheets = []) {
        this.sheets = new Map(sheets.map((sheet) => [sheet.name, sheet]));
    }

    getSheetByName(name) {
        return this.sheets.get(name) || null;
    }

    insertSheet(name) {
        const sheet = new MockSheet(name);
        this.sheets.set(name, sheet);
        return sheet;
    }
}

class MockResponse {
    constructor(code, body) {
        this.code = code;
        this.body = body;
    }

    getResponseCode() {
        return this.code;
    }

    getContentText() {
        return this.body;
    }
}

function createFixedDate(nowMs) {
    return class FixedDate extends Date {
        constructor(...args) {
            super(...(args.length === 0 ? [nowMs] : args));
        }

        static now() {
            return nowMs;
        }

        static parse(value) {
            return Date.parse(value);
        }

        static UTC(...args) {
            return Date.UTC(...args);
        }
    };
}

function formatDate(date, timezone, pattern) {
    assert.equal(timezone, "Asia/Tokyo");
    assert.equal(pattern, "yyyy/MM/dd HH:mm:ss");

    const parts = new Intl.DateTimeFormat("en-CA", {
        timeZone: timezone,
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
        hourCycle: "h23",
    }).formatToParts(date);
    const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));

    return `${values.year}/${values.month}/${values.day} ${values.hour}:${values.minute}:${values.second}`;
}

function createRuntime(options = {}) {
    const nowMs = options.nowMs || Date.parse("2024-01-02T03:04:05Z");
    const properties = options.properties || new MockProperties();
    const logSheet = options.logSheet || new MockSheet("地震ログ");
    const spreadsheet = options.spreadsheet || new MockSpreadsheet([logSheet]);
    const fetchCalls = [];
    const fetchQueue = [...(options.fetchQueue || [])];
    const lock = options.lock || {
        released: false,
        tryLock: () => true,
        releaseLock() {
            this.released = true;
        },
    };

    const context = {
        Buffer,
        Intl,
        console,
        Date: createFixedDate(nowMs),
        LockService: {
            getScriptLock: () => lock,
        },
        PropertiesService: {
            getScriptProperties: () => properties,
        },
        SpreadsheetApp: {
            getActiveSpreadsheet: () => spreadsheet,
        },
        UrlFetchApp: {
            fetch(url, params = {}) {
                fetchCalls.push({ url, params });

                if (options.fetch) {
                    return options.fetch(url, params, fetchCalls.length);
                }

                const response = fetchQueue.shift();

                if (!response) {
                    throw new Error(`Unexpected fetch: ${url}`);
                }

                return response;
            },
        },
        Utilities: {
            base64EncodeWebSafe(value) {
                return Buffer.from(value).toString("base64url");
            },
            computeRsaSha256Signature(value) {
                return Buffer.from(`signed:${value}`);
            },
            formatDate,
        },
    };

    vm.createContext(context);

    const source = sourceFiles
        .map((file) => fs.readFileSync(path.join(rootDir, file), "utf8"))
        .join("\n\n");
    const exportSource = `\nglobalThis.__exports = { ${exportNames.join(", ")} };`;

    vm.runInContext(source + exportSource, context, { filename: "gas-source.js" });

    return {
        ...context.__exports,
        context,
        properties,
        spreadsheet,
        logSheet,
        fetchCalls,
        lock,
    };
}

function earthquakeEvent(overrides = {}) {
    return {
        id: "event-1",
        earthquake: {
            time: "2024-01-02T03:04:05Z",
            maxScale: 45,
            hypocenter: {
                name: "東京湾",
            },
        },
        ...overrides,
    };
}

module.exports = {
    MockProperties,
    MockResponse,
    MockSheet,
    MockSpreadsheet,
    createRuntime,
    earthquakeEvent,
};
