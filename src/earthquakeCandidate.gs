// 地震イベントを通知判定用の候補へ変換するファイル

function buildEarthquakeCandidate(event) {
    if (!event || typeof event !== "object") {
        return { candidate: null, reason: "invalid_event_shape", eventId: null };
    }

    const eventId = event.id ? String(event.id) : null;
    const earthquake = event.earthquake;

    if (!earthquake || typeof earthquake !== "object") {
        return { candidate: null, reason: "missing_earthquake_data", eventId: eventId };
    }

    const earthquakeTime = earthquake.time ? String(earthquake.time).trim() : "";

    if (earthquakeTime === "") {
        return { candidate: null, reason: "missing_earthquake_time", eventId: eventId };
    }

    let hypocenterName = "";

    if (
        earthquake.hypocenter &&
        typeof earthquake.hypocenter === "object" &&
        earthquake.hypocenter.name
    ) {
        hypocenterName = String(earthquake.hypocenter.name).trim();
    }

    if (hypocenterName === "") {
        hypocenterName = "UNKNOWN";
    }

    const rawScale = Number(earthquake.maxScale || 0);
    const maxScale = isNaN(rawScale) ? 0 : rawScale;

    return {
        candidate: {
            eventId: eventId,
            earthquakeTime: earthquakeTime,
            hypocenterName: hypocenterName,
            maxScale: maxScale,
            dedupeKey: earthquakeTime + "|" + hypocenterName,
        },
        reason: null,
    };
}

// P2PQuakeの時刻はタイムゾーンなしの「yyyy/MM/dd HH:mm:ss」で返るため、
// 実行環境のタイムゾーンに依存しないよう東京時間として解釈する
function parseTokyoTime(value) {
    if (value === null || value === undefined) {
        return null;
    }

    const text = String(value).trim();

    if (text === "") {
        return null;
    }

    try {
        if (/^\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2}$/.test(text)) {
            return Utilities.parseDate(text, "Asia/Tokyo", "yyyy/MM/dd HH:mm:ss");
        }

        if (/^\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}$/.test(text)) {
            return Utilities.parseDate(text, "Asia/Tokyo", "yyyy/MM/dd HH:mm");
        }

        const date = new Date(text);

        return isNaN(date.getTime()) ? null : date;
    } catch (e) {
        return null;
    }
}

function formatEarthquakeTime(value) {
    const date = value instanceof Date ? value : parseTokyoTime(value);

    if (!date) {
        return String(value || "");
    }

    return Utilities.formatDate(date, "Asia/Tokyo", "yyyy/MM/dd HH:mm:ss");
}

function scaleText(maxScale) {
    return SCALE_NAMES[maxScale] || "不明";
}

function pendingUnknownRecord(candidate, now) {
    const time = now instanceof Date ? now : new Date();

    return {
        dedupeKey: candidate.dedupeKey,
        eventId: candidate.eventId,
        earthquakeTime: candidate.earthquakeTime,
        hypocenterName: candidate.hypocenterName,
        maxScale: candidate.maxScale,
        firstSeenAt: time.toISOString(),
    };
}

function candidateFromPendingUnknown(earthquakeTime, pending) {
    const storedTime = pending.earthquakeTime
        ? String(pending.earthquakeTime).trim()
        : String(earthquakeTime).trim();

    if (storedTime === "") {
        return null;
    }

    const hypocenterName = pending.hypocenterName
        ? String(pending.hypocenterName).trim()
        : "UNKNOWN";

    const dedupeKey = pending.dedupeKey
        ? String(pending.dedupeKey).trim()
        : storedTime + "|" + hypocenterName;

    return {
        eventId: pending.eventId || null,
        earthquakeTime: storedTime,
        hypocenterName: hypocenterName === "" ? "UNKNOWN" : hypocenterName,
        maxScale: Number(pending.maxScale || 0),
        dedupeKey: dedupeKey,
    };
}

function compareTimes(left, right) {
    const leftDate = parseTokyoTime(left);
    const rightDate = parseTokyoTime(right);

    if (leftDate && rightDate) {
        return leftDate.getTime() - rightDate.getTime();
    }

    const leftText = String(left);
    const rightText = String(right);

    if (leftText < rightText) {
        return -1;
    }

    if (leftText > rightText) {
        return 1;
    }

    return 0;
}
