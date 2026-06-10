// 設定値・文言を管理するファイル

const NOTIFY_SCALE = 0;

const P2PQUAKE_API_URL = "https://api.p2pquake.net/v2/jma/quake?limit=1";

const LINEWORKS_TOKEN_URL = "https://auth.worksmobile.com/oauth2/v2.0/token";

const LOG_SHEET_NAME = "地震ログ";

const HEALTH_SHEET_NAME = "ヘルスチェック";

const SCALE_NAMES = {
    10: "1",
    20: "2",
    30: "3",
    40: "4",
    45: "5弱",
    50: "5強",
    55: "6弱",
    60: "6強",
    70: "7",
};

const HEALTH_KEYS = {
    successCount: "HEALTH_SUCCESS_COUNT",
    failureCount: "HEALTH_FAILURE_COUNT",
    lastSuccessTime: "HEALTH_LAST_SUCCESS_TIME",
    lastErrorTime: "HEALTH_LAST_ERROR_TIME",
    lastErrorMessage: "HEALTH_LAST_ERROR_MESSAGE",
    periodStartTime: "HEALTH_PERIOD_START_TIME",
};

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

// HTTPステータスとレスポンス本文を含むエラーを作成する。
function createHttpError(message, code, body) {
    return new Error(`${message} HTTP:${code} ${body}`);
}
