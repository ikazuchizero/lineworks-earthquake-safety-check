// P2PQuake APIから地震情報を取得するファイル

function fetchEarthquakes(config) {
    const url = buildP2pquakeUrl(config);
    const maxAttempts = 3;
    const retryWaitMs = [10000, 20000];
    let lastError = null;

    for (let attempt = 0; attempt < maxAttempts; attempt++) {
        const response = UrlFetchApp.fetch(url, {
            muteHttpExceptions: true,
        });

        const code = response.getResponseCode();
        const text = response.getContentText();

        if (code >= 200 && code < 300) {
            let events;

            try {
                events = JSON.parse(text);
            } catch (e) {
                throw new Error(
                    ERROR_MESSAGES.earthquakeApiResponseInvalid + ": " + e.message
                );
            }

            if (!Array.isArray(events)) {
                throw new Error(ERROR_MESSAGES.earthquakeApiResponseInvalid);
            }

            return events;
        }

        lastError = createHttpError(ERROR_MESSAGES.earthquakeApiFailed, code, text);

        // 429（レート制限）と5xxだけ再試行する。4xxは待っても結果が変わらない
        const retryable = code === 429 || code >= 500;

        if (!retryable || attempt === maxAttempts - 1) {
            throw lastError;
        }

        console.log(
            "地震API再試行: HTTP=" +
                code +
                "（" +
                (attempt + 1) +
                "/" +
                maxAttempts +
                "回目、" +
                retryWaitMs[attempt] / 1000 +
                "秒待機）"
        );

        Utilities.sleep(retryWaitMs[attempt]);
    }

    throw lastError;
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
