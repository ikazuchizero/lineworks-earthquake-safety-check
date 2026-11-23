// エラー文言・ログ文言を管理するファイル

const ERROR_MESSAGES = {
    earthquakeApiFailed: "地震API取得失敗",
    earthquakeApiResponseInvalid: "地震APIレスポンス不正",

    spreadsheetMissing:
        "SPREADSHEET_ID未設定です。スプレッドシートにバインドするか、SPREADSHEET_IDを設定してください",

    notifyScaleInvalid: "NOTIFY_SCALEが不正です（0,10,20,30,40,45,50,55,60,70のいずれか）",
    unknownHoldInvalid: "UNKNOWN_HYPOCENTER_HOLD_SECONDSは1以上で設定してください",
    quietHoursInvalid: "QUIET_HOURS_START/ENDは0〜23で設定してください",
    quietHoursSame: "QUIET_HOURS_STARTとQUIET_HOURS_ENDは別の値にしてください",

    botIdMissing: "BOT_ID未設定",
    roomIdMissing: "ROOM_ID未設定",

    tokenFailed: "トークン取得失敗",
    lineWorksSendFailed: "LINE WORKS送信失敗",

    templateNotSupported: "テンプレートが公開APIに対応していません",
    templateCollectsEmail:
        "テンプレートがメールアドレスを収集しています。匿名回答できるよう設定を変更してください",
    formPublishFailed: "Googleフォームの公開に失敗しました",
    formUrlMissing: "Googleフォームの回答URLを取得できませんでした",
    formCopyFailed: "Googleフォームの複製に失敗しました",

    stateJsonInvalid: "状態シートのJSONが不正です",
    stateSheetInvalid: "状態シートの形式が不正です",
};

function propertyMissingMessage(key) {
    return key + "未設定";
}

function propertyNotIntegerMessage(key) {
    return key + "は整数で設定してください";
}

ERROR_MESSAGES.propertyMissing = propertyMissingMessage;
ERROR_MESSAGES.propertyNotInteger = propertyNotIntegerMessage;

const LOG_MESSAGES = {
    success: "送信成功",
    duplicate: "重複パス",
    skipScale: "通知対象外",
    noEvent: "地震データなし",
    invalidEvent: "地震データ不正",
    unknownPending: "震源不明のため保留",
    unknownExpired: "震源不明の保留期限切れ",
    quietCaptured: "静穏時間のため保留",
    quietReleased: "静穏時間終了後に通知",
    belowScale: "通知対象外",
    inflightRetry: "前回の送信結果不明のため再送",
};

function createHttpError(message, code, body) {
    return new Error(`${message} HTTP:${code} ${body}`);
}
