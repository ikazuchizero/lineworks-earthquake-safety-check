// LINE WORKSへメッセージを送信するファイル

function sendLineWorksMessage(accessToken, properties, roomId, text) {
    const botId = properties.getProperty(PROPERTY_KEYS.botId);

    if (!botId) {
        throw new Error(ERROR_MESSAGES.botIdMissing);
    }

    if (!roomId) {
        throw new Error(ERROR_MESSAGES.roomIdMissing);
    }

    const url =
        "https://www.worksapis.com/v1.0/bots/" +
        encodeURIComponent(botId) +
        "/channels/" +
        encodeURIComponent(roomId) +
        "/messages";

    const response = UrlFetchApp.fetch(url, {
        method: "post",
        headers: {
            Authorization: "Bearer " + accessToken,
            "Content-Type": "application/json",
        },
        payload: JSON.stringify({
            content: {
                type: "text",
                text: text,
            },
        }),
        muteHttpExceptions: true,
    });

    const code = response.getResponseCode();

    console.log("LINE WORKS HTTP=" + code);

    if (code < 200 || code >= 300) {
        // レスポンス本文には秘密情報が含まれ得るため、ステータスだけを残す
        throw createHttpError(ERROR_MESSAGES.lineWorksSendFailed, code, "");
    }
}
