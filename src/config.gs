// 設定値を管理するファイル

//送信する震度
const NOTIFY_SCALE = 0;

const P2PQUAKE_API_URL = "https://api.p2pquake.net/v2/jma/quake?limit=1";

const LINEWORKS_TOKEN_URL = "https://auth.worksmobile.com/oauth2/v2.0/token";

const LOG_SHEET_NAME = "地震ログ";

//震度を数値化
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
