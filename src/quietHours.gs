// 静穏時間とUNKNOWN震源地の保留判定を管理するファイル

function getTokyoHour(date) {
    return Number(Utilities.formatDate(date, "Asia/Tokyo", "H"));
}

// 発生時刻が静穏時間に入る場合、その静穏時間帯の開始・終了を返す
function quietWindowFor(value, config) {
    const date = value instanceof Date ? value : parseTokyoTime(value);

    if (!date) {
        return null;
    }

    const hour = getTokyoHour(date);
    const start = config.quietHoursStart;
    const end = config.quietHoursEnd;

    const inQuiet =
        start < end
            ? hour >= start && hour < end
            : hour >= start || hour < end;

    if (!inQuiet) {
        return null;
    }

    const dateKey = Utilities.formatDate(date, "Asia/Tokyo", "yyyy/MM/dd");
    let base = Utilities.parseDate(
        dateKey + " 00:00:00",
        "Asia/Tokyo",
        "yyyy/MM/dd HH:mm:ss"
    );

    if (start > end && hour < end) {
        base = new Date(base.getTime() - 24 * 60 * 60 * 1000);
    }

    const windowStart = new Date(base.getTime() + start * 60 * 60 * 1000);
    const endHour = start < end ? end : end + 24;
    const windowEnd = new Date(base.getTime() + endHour * 60 * 60 * 1000);

    return {
        windowStart: windowStart.toISOString(),
        windowEnd: windowEnd.toISOString(),
    };
}

function quietBatchHasEnded(batch, now) {
    if (!batch) {
        return false;
    }

    const end = parseTokyoTime(batch.windowEnd);

    return !!end && end.getTime() <= now.getTime();
}

function isQuietNow(config) {
    const hour = getTokyoHour(new Date());
    const start = config.quietHoursStart;
    const end = config.quietHoursEnd;

    return start < end
        ? hour >= start && hour < end
        : hour >= start || hour < end;
}

// 同じ静穏時間帯では、震度が大きいもの、同震度なら後発を採用する
function isBetterQuietCandidate(candidate, existing) {
    if (!existing) {
        return true;
    }

    if (String(candidate.earthquakeTime) === String(existing.earthquakeTime || "")) {
        return (
            candidate.hypocenterName !== "UNKNOWN" &&
            (existing.hypocenterName || "UNKNOWN") === "UNKNOWN"
        );
    }

    const candidateScale = Number(candidate.maxScale || 0);
    const existingScale = Number(existing.maxScale || 0);

    if (candidateScale !== existingScale) {
        return candidateScale > existingScale;
    }

    return compareTimes(candidate.earthquakeTime, existing.earthquakeTime) > 0;
}

function isPendingUnknownExpired(pending, now, config) {
    if (!pending) {
        return false;
    }

    const firstSeenAt = parseTokyoTime(pending.firstSeenAt);

    if (!firstSeenAt) {
        return true;
    }

    return now.getTime() - firstSeenAt.getTime() >= config.unknownHypocenterHoldSeconds * 1000;
}

function captureQuietCandidate(candidate, window, state, config) {
    const completedThrough = state.quietCompletedThrough;

    if (completedThrough && compareTimes(window.windowEnd, completedThrough) <= 0) {
        return false;
    }

    const batch = state.quietBatch;

    if (batch && String(batch.windowStart) !== String(window.windowStart)) {
        return false;
    }

    if (batch && !isBetterQuietCandidate(candidate, batch.candidate)) {
        return false;
    }

    state.quietBatch = {
        windowStart: window.windowStart,
        windowEnd: window.windowEnd,
        candidate: candidate,
    };

    return true;
}

function pendingUnknownHasUnfinishedQuietBatch(earthquakeTime, state, now) {
    const batch = state.quietBatch;

    if (!batch || !batch.candidate) {
        return false;
    }

    if (String(batch.candidate.earthquakeTime) !== String(earthquakeTime)) {
        return false;
    }

    return !quietBatchHasEnded(batch, now);
}

function removePendingUnknownsForQuietWindow(
    windowStart,
    state,
    config,
    exceptEarthquakeTime
) {
    let changed = false;

    for (const earthquakeTime of Object.keys(state.pendingUnknown)) {
        if (
            exceptEarthquakeTime &&
            String(earthquakeTime) === String(exceptEarthquakeTime)
        ) {
            continue;
        }

        const window = quietWindowFor(earthquakeTime, config);

        if (window && String(window.windowStart) === String(windowStart)) {
            delete state.pendingUnknown[earthquakeTime];
            changed = true;
        }
    }

    return changed;
}
