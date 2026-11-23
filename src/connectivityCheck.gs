// LINE WORKSへの疎通確認を行うファイル

function connectivityCheck() {
    const properties = PropertiesService.getScriptProperties();
    const config = loadConfig(properties);

    if (isQuietNow(config)) {
        console.log("静穏時間のため疎通確認は送信しません");
        return;
    }

    const accessToken = getAccessToken(properties);

    sendLineWorksMessage(
        accessToken,
        properties,
        config.roomId,
        "安否確認bot 通知用チャット疎通確認です。地震通知ではありません。"
    );

    console.log("疎通確認メッセージを送信しました");
}
