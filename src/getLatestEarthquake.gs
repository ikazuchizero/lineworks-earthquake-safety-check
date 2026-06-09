// P2PQuake APIから最新地震情報を取得するファイル

function getLatestEarthquake() {
    const response = UrlFetchApp.fetch(P2PQUAKE_API_URL, {
        muteHttpExceptions: true,
    });

    const code = response.getResponseCode();
    const text = response.getContentText();

    if (code !== 200) {
        throw createHttpError(ERROR_MESSAGES.earthquakeApiFailed, code, text);
    }

    const events = JSON.parse(text);

    if (!events || events.length === 0) {
        return null;
    }

    return events[0];
}
