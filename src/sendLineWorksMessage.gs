// LINE WORKSへメッセージを送信するファイル

function sendLineWorksMessage(accessToken, properties, roomId, text) {
    const botId = properties.getProperty("BOT_ID");

    if (!botId) {
        throw new Error(ERROR_MESSAGES.botIdMissing);
    }

    if (!roomId) {
        throw new Error(ERROR_MESSAGES.roomIdMissing);
    }

    const url = `https://www.worksapis.com/v1.0/bots/${botId}/channels/${roomId}/messages`;

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
    const body = response.getContentText();

    Logger.log("LINE WORKS HTTP=" + code);
    Logger.log("LINE WORKS BODY=" + body);

    if (code < 200 || code >= 300) {
        throw createHttpError(ERROR_MESSAGES.lineWorksSendFailed, code, body);
    }
}
