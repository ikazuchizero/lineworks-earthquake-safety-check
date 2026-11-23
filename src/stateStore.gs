// 状態シートへの読み書きを管理するファイル

function createEmptyState() {
    return {
        notified: {},
        notifiedByTime: {},
        pendingUnknown: {},
        quietBatch: null,
        quietCompletedThrough: null,
        forms: {},
    };
}

function loadState(context) {
    const sheet = context.stateSheet;
    const state = createEmptyState();
    const lastRow = sheet.getLastRow();

    if (lastRow < 2) {
        return state;
    }

    const values = sheet
        .getRange(2, 1, lastRow - 1, STATE_HEADERS.length)
        .getValues();

    for (let i = 0; i < values.length; i++) {
        const row = values[i];
        const type = String(row[0] || "").trim();
        const key = String(row[1] || "").trim();
        const raw = row[2];

        if (type === "" || key === "") {
            continue;
        }

        const rowNumber = i + 2;

        switch (type) {
            case "notified":
                state.notified[key] = parseStateJson(raw, type, key, rowNumber);
                break;
            case "notified_time":
                state.notifiedByTime[key] = parseStateJson(raw, type, key, rowNumber);
                break;
            case "pending":
                state.pendingUnknown[key] = parseStateJson(raw, type, key, rowNumber);
                break;
            case "form":
                state.forms[key] = parseStateJson(raw, type, key, rowNumber);
                break;
            case "quiet":
                state.quietBatch = parseStateJsonOrNull(raw, type, key, rowNumber);
                break;
            case "quiet_completed":
                state.quietCompletedThrough =
                    raw === null || raw === undefined || String(raw).trim() === ""
                        ? null
                        : String(raw);
                break;
            default:
                console.log("未知の状態種別を無視しました: " + type + "（" + rowNumber + "行目）");
        }
    }

    return state;
}

function parseStateJson(raw, type, key, rowNumber) {
    const text = raw === null || raw === undefined ? "" : String(raw).trim();

    if (text === "") {
        throw new Error(
            ERROR_MESSAGES.stateJsonInvalid +
                "（" +
                rowNumber +
                "行目 " +
                type +
                " / " +
                key +
                " が空です）"
        );
    }

    let parsed;

    try {
        parsed = JSON.parse(text);
    } catch (e) {
        throw new Error(
            ERROR_MESSAGES.stateJsonInvalid +
                "（" +
                rowNumber +
                "行目 " +
                type +
                " / " +
                key +
                ": " +
                e.message +
                "）"
        );
    }

    if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) {
        throw new Error(
            ERROR_MESSAGES.stateJsonInvalid +
                "（" +
                rowNumber +
                "行目 " +
                type +
                " / " +
                key +
                " がオブジェクトではありません）"
        );
    }

    return parsed;
}

function parseStateJsonOrNull(raw, type, key, rowNumber) {
    if (raw === null || raw === undefined || String(raw).trim() === "") {
        return null;
    }

    return parseStateJson(raw, type, key, rowNumber);
}

function saveState(context, state) {
    const now = new Date();
    pruneState(state, now, context.config);

    const updatedAt = now.toISOString();
    const rows = [];

    for (const key of Object.keys(state.notified)) {
        rows.push(["notified", key, JSON.stringify(state.notified[key]), updatedAt]);
    }

    for (const key of Object.keys(state.notifiedByTime)) {
        rows.push(["notified_time", key, JSON.stringify(state.notifiedByTime[key]), updatedAt]);
    }

    for (const key of Object.keys(state.pendingUnknown)) {
        rows.push(["pending", key, JSON.stringify(state.pendingUnknown[key]), updatedAt]);
    }

    for (const key of Object.keys(state.forms)) {
        rows.push(["form", key, JSON.stringify(state.forms[key]), updatedAt]);
    }

    rows.push([
        "quiet",
        "current",
        state.quietBatch ? JSON.stringify(state.quietBatch) : "",
        updatedAt,
    ]);

    rows.push([
        "quiet_completed",
        "current",
        state.quietCompletedThrough || "",
        updatedAt,
    ]);

    writeStateRows(context.stateSheet, rows);
}

function writeStateRows(sheet, rows) {
    const existingRows = Math.max(sheet.getLastRow() - 1, 0);

    if (rows.length > 0) {
        sheet.getRange(2, 1, rows.length, STATE_HEADERS.length).setValues(rows);
    }

    if (existingRows > rows.length) {
        sheet
            .getRange(
                rows.length + 2,
                1,
                existingRows - rows.length,
                STATE_HEADERS.length
            )
            .clearContent();
    }
}

function pruneState(state, now, config) {
    const cutoff = now.getTime() - config.stateRetentionDays * 24 * 60 * 60 * 1000;

    for (const key of Object.keys(state.notified)) {
        const record = state.notified[key];
        const notifiedAt = record && record.notifiedAt ? parseTokyoTime(record.notifiedAt) : null;

        if (!notifiedAt || notifiedAt.getTime() < cutoff) {
            const earthquakeTime = record ? record.earthquakeTime : null;
            delete state.notified[key];

            if (earthquakeTime) {
                delete state.notifiedByTime[earthquakeTime];
            }
        }
    }

    const notifiedKeys = Object.keys(state.notified);

    if (notifiedKeys.length > config.maxNotifiedRecords) {
        const sorted = notifiedKeys
            .map(function (key) {
                const record = state.notified[key];
                const notifiedAt = record && record.notifiedAt
                    ? parseTokyoTime(record.notifiedAt)
                    : null;
                return {
                    key: key,
                    time: notifiedAt ? notifiedAt.getTime() : 0,
                    earthquakeTime: record ? record.earthquakeTime : null,
                };
            })
            .sort(function (left, right) {
                return left.time - right.time;
            });

        const removeCount = sorted.length - config.maxNotifiedRecords;

        for (let i = 0; i < removeCount; i++) {
            delete state.notified[sorted[i].key];

            if (sorted[i].earthquakeTime) {
                delete state.notifiedByTime[sorted[i].earthquakeTime];
            }
        }
    }

    for (const key of Object.keys(state.notifiedByTime)) {
        const record = state.notifiedByTime[key];
        const notifiedAt = record && record.notifiedAt ? parseTokyoTime(record.notifiedAt) : null;

        if (!notifiedAt || notifiedAt.getTime() < cutoff) {
            delete state.notifiedByTime[key];
        }
    }

    for (const key of Object.keys(state.forms)) {
        const record = state.forms[key];
        const createdAt = record && record.createdAt ? parseTokyoTime(record.createdAt) : null;

        if (!createdAt || createdAt.getTime() < cutoff) {
            delete state.forms[key];
        }
    }

    for (const key of Object.keys(state.pendingUnknown)) {
        const record = state.pendingUnknown[key];
        const firstSeenAt = record && record.firstSeenAt
            ? parseTokyoTime(record.firstSeenAt)
            : null;

        if (!firstSeenAt || firstSeenAt.getTime() < cutoff) {
            delete state.pendingUnknown[key];
        }
    }
}

function clearState(context) {
    const sheet = context.stateSheet;
    const existingRows = Math.max(sheet.getLastRow() - 1, 0);

    if (existingRows > 0) {
        sheet.getRange(2, 1, existingRows, STATE_HEADERS.length).clearContent();
    }
}
