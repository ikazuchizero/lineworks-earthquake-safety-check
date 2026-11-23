// P2PQuake APIから地震情報を取得するファイル

function fetchEarthquakes(config) {
    const url = buildP2pquakeUrl(config);

    const response = UrlFetchApp.fetch(url, {
        muteHttpExceptions: true,
    });

    const code = response.getResponseCode();
    const text = response.getContentText();

    if (code < 200 || code >= 300) {
        throw createHttpError(ERROR_MESSAGES.earthquakeApiFailed, code, text);
    }

    let events;

    try {
        events = JSON.parse(text);
    } catch (e) {
        throw new Error(ERROR_MESSAGES.earthquakeApiResponseInvalid + ": " + e.message);
    }

    if (!Array.isArray(events)) {
        throw new Error(ERROR_MESSAGES.earthquakeApiResponseInvalid);
    }

    return events;
}

// URLに既存のlimitがあっても、設定した件数で上書きする
function buildP2pquakeUrl(config) {
    const base = String(config.p2pquakeApiUrl);
    const hashIndex = base.indexOf("#");
    const withoutHash = hashIndex === -1 ? base : base.slice(0, hashIndex);
    const queryIndex = withoutHash.indexOf("?");
    const path = queryIndex === -1 ? withoutHash : withoutHash.slice(0, queryIndex);
    const query = queryIndex === -1 ? "" : withoutHash.slice(queryIndex + 1);

    const params = query
        .split("&")
        .filter(function (part) {
            return part !== "" && part.split("=")[0] !== "limit";
        });

    params.push("limit=" + encodeURIComponent(String(config.p2pquakeLimit)));

    return path + "?" + params.join("&");
}
