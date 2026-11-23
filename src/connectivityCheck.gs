// LINE WORKSへの疎通確認を行うファイル
// 初回設置時に、通常の通知先と（設定されていれば）保守通知先の両方を確認する。
// 地震通知と誤認されないよう、短い検証メッセージだけを送る。

function connectivityCheck() {
    const properties = PropertiesService.getScriptProperties();
    const config = loadConfig(properties);
    const accessToken = getAccessToken(properties, config);

    sendLineWorksMessage(
        accessToken,
        properties,
        config.roomId,
        "安否確認bot 通知用チャット疎通確認です。地震通知ではありません。"
    );

    if (
        config.maintenanceRoomId &&
        String(config.maintenanceRoomId) !== String(config.roomId)
    ) {
        sendLineWorksMessage(
            accessToken,
            properties,
            config.maintenanceRoomId,
            "安否確認bot 保守通知用チャット疎通確認です。地震通知ではありません。"
        );
        console.log("疎通確認メッセージを送信しました（通知用・保守用）");
        return;
    }

    console.log("疎通確認メッセージを送信しました（通知用）");
}
