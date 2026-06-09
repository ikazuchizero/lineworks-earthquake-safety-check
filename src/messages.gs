// エラー文言・ログ文言を管理するファイル

const ERROR_MESSAGES = {
    earthquakeApiFailed: "地震API取得失敗",
    earthquakeIdMissing: "地震ID取得失敗",

    clientIdMissing: "CLIENT_ID未設定",
    clientSecretMissing: "CLIENT_SECRET未設定",
    serviceAcctMissing: "SERVICE_ACCT未設定",
    privateKeyMissing: "PRIVATE_KEY未設定",

    botIdMissing: "BOT_ID未設定",
    roomIdMissing: "ROOM_ID未設定",

    tokenFailed: "トークン取得失敗",
    lineWorksSendFailed: "LINE WORKS送信失敗",
};

const LOG_MESSAGES = {
    success: "送信成功",
    duplicate: "重複パス",
    skipScale: "通知対象外",
    noEvent: "地震データ取得失敗",
    noEarthquakeData: "earthquakeデータなし",
};

function createHttpError(message, code, body) {
    return new Error(`${message} HTTP:${code} ${body}`);
}
