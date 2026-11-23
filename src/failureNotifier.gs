// 処理失敗時に保守用チャットへ通知するファイル

function notifyFailure(context, error) {
    if (!context || !context.config) {
        return;
    }

    const message = error && error.message ? error.message : String(error);

    // LINE WORKS自体の障害で再帰通知にならないようにする
    if (
        message.indexOf("LINE WORKS") !== -1 ||
        message.indexOf(ERROR_MESSAGES.tokenFailed) !== -1
    ) {
        return;
    }

    const properties = context.properties;
    const fingerprint = fingerprintError("check_failed", error);
    const lastFingerprint = properties.getProperty(PROPERTY_KEYS.failureFingerprint);
    const lastTime = Number(
        properties.getProperty(PROPERTY_KEYS.failureNotifyTime) || 0
    );
    const now = Date.now();

    if (
        lastFingerprint === fingerprint &&
        now - lastTime < context.config.failureNotifySuppressSeconds * 1000
    ) {
        return;
    }

    const accessToken = getAccessToken(properties, context.config);

    sendLineWorksMessage(
        accessToken,
        properties,
        context.config.maintenanceRoomId,
        "安否確認botで処理失敗が発生しました。分類: check_failed。実行ログを確認してください。"
    );

    properties.setProperty(PROPERTY_KEYS.failureFingerprint, fingerprint);
    properties.setProperty(PROPERTY_KEYS.failureNotifyTime, String(now));
}

// PHP版の fingerprint と同じく、分類・例外クラス相当（name）・メッセージから作る。
// GAS の例外には PHP のクラス階層がないため、Error.name をクラス相当として使う。
function fingerprintError(category, error) {
    const message = error && error.message ? error.message : String(error);
    const name = error && error.name ? String(error.name) : "Error";
    const digest = Utilities.computeDigest(
        Utilities.DigestAlgorithm.SHA_256,
        category + "|" + name + "|" + message
    );

    return digest
        .map(function (byte) {
            const value = (byte < 0 ? byte + 256 : byte).toString(16);
            return value.length === 1 ? "0" + value : value;
        })
        .join("");
}
