// 設定値とスクリプトプロパティのキーを管理するファイル

const CONFIG = {
    // 通知する震度の下限（P2PQuakeのmaxScaleコード）
    notifyScale: 45,
    // 震源不明（UNKNOWN）の続報を待つ秒数
    unknownHypocenterHoldSeconds: 600,
    // 全体通知を保留する時間帯（東京時間、開始を含み終了を含まない）
    quietHoursStart: 0,
    quietHoursEnd: 6,
    // P2PQuakeから取得する件数（1件だけだと通知対象を見落とす）
    p2pquakeLimit: 10,
    // 1時間あたりの想定実行回数（1分トリガーなら60）
    expectedRunsPerHour: 60,
    // 状態シートに残す日数
    stateRetentionDays: 30,
    // 通知済み履歴の最大件数
    maxNotifiedRecords: 500,
    // 同じ障害通知を抑止する秒数
    failureNotifySuppressSeconds: 3600,
    // 複製するフォーム名の接頭辞
    formTitlePrefix: "安否確認",
    // 外部API
    p2pquakeApiUrl: "https://api.p2pquake.net/v2/jma/quake",
    lineWorksTokenUrl: "https://auth.worksmobile.com/oauth2/v2.0/token",
};

const SHEET_NAMES = {
    log: "地震ログ",
    health: "ヘルスチェック",
    state: "状態",
};

const LOG_HEADERS = [
    "記録時刻",
    "イベントID",
    "発生時刻",
    "震源地",
    "最大震度",
    "結果",
    "詳細",
];

const HEALTH_HEADERS = [
    "集計時刻",
    "対象開始",
    "対象終了",
    "予定実行回数",
    "実行回数",
    "成功数",
    "失敗数",
    "正常動作率",
    "最終成功時刻",
    "最終エラー時刻",
    "最終エラー内容",
];

const STATE_HEADERS = ["種別", "キー", "値", "更新日時"];

// 震度コードを表示名へ変換
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

const ALLOWED_NOTIFY_SCALES = [0, 10, 20, 30, 40, 45, 50, 55, 60, 70];

// スクリプトプロパティのキー
const PROPERTY_KEYS = {
    clientId: "CLIENT_ID",
    clientSecret: "CLIENT_SECRET",
    serviceAcct: "SERVICE_ACCT",
    privateKey: "PRIVATE_KEY",
    botId: "BOT_ID",
    roomId: "ROOM_ID",
    maintenanceRoomId: "MAINTENANCE_ROOM_ID",
    formTemplateId: "FORM_TEMPLATE_ID",
    spreadsheetId: "SPREADSHEET_ID",
    notifyScale: "NOTIFY_SCALE",
    unknownHoldSeconds: "UNKNOWN_HYPOCENTER_HOLD_SECONDS",
    quietHoursStart: "QUIET_HOURS_START",
    quietHoursEnd: "QUIET_HOURS_END",
    lastRoutineLogTime: "LAST_ROUTINE_LOG_TIME",
    failureFingerprint: "FAILURE_NOTIFY_FINGERPRINT",
    failureNotifyTime: "FAILURE_NOTIFY_TIME",
    healthSuccessCount: "HEALTH_SUCCESS_COUNT",
    healthFailureCount: "HEALTH_FAILURE_COUNT",
    healthLastSuccessTime: "HEALTH_LAST_SUCCESS_TIME",
    healthLastErrorTime: "HEALTH_LAST_ERROR_TIME",
    healthLastErrorMessage: "HEALTH_LAST_ERROR_MESSAGE",
    healthPeriodStartTime: "HEALTH_PERIOD_START_TIME",
};

function loadConfig(properties) {
    const requiredKeys = [
        PROPERTY_KEYS.clientId,
        PROPERTY_KEYS.clientSecret,
        PROPERTY_KEYS.serviceAcct,
        PROPERTY_KEYS.privateKey,
        PROPERTY_KEYS.botId,
        PROPERTY_KEYS.roomId,
        PROPERTY_KEYS.formTemplateId,
    ];

    const values = {};
    for (const key of requiredKeys) {
        values[key] = requireProperty(properties, key);
    }

    const notifyScale = readIntegerProperty(
        properties,
        PROPERTY_KEYS.notifyScale,
        CONFIG.notifyScale
    );

    if (ALLOWED_NOTIFY_SCALES.indexOf(notifyScale) === -1) {
        throw new Error(ERROR_MESSAGES.notifyScaleInvalid);
    }

    const unknownHypocenterHoldSeconds = readIntegerProperty(
        properties,
        PROPERTY_KEYS.unknownHoldSeconds,
        CONFIG.unknownHypocenterHoldSeconds
    );

    if (unknownHypocenterHoldSeconds <= 0) {
        throw new Error(ERROR_MESSAGES.unknownHoldInvalid);
    }

    const quietHoursStart = readIntegerProperty(
        properties,
        PROPERTY_KEYS.quietHoursStart,
        CONFIG.quietHoursStart
    );

    const quietHoursEnd = readIntegerProperty(
        properties,
        PROPERTY_KEYS.quietHoursEnd,
        CONFIG.quietHoursEnd
    );

    if (quietHoursStart < 0 || quietHoursStart > 23 || quietHoursEnd < 0 || quietHoursEnd > 23) {
        throw new Error(ERROR_MESSAGES.quietHoursInvalid);
    }

    if (quietHoursStart === quietHoursEnd) {
        throw new Error(ERROR_MESSAGES.quietHoursSame);
    }

    const maintenanceRoomId =
        readOptionalProperty(properties, PROPERTY_KEYS.maintenanceRoomId) ||
        values[PROPERTY_KEYS.roomId];

    return {
        clientId: values[PROPERTY_KEYS.clientId],
        clientSecret: values[PROPERTY_KEYS.clientSecret],
        serviceAcct: values[PROPERTY_KEYS.serviceAcct],
        privateKey: values[PROPERTY_KEYS.privateKey],
        botId: values[PROPERTY_KEYS.botId],
        roomId: values[PROPERTY_KEYS.roomId],
        maintenanceRoomId: maintenanceRoomId,
        formTemplateId: values[PROPERTY_KEYS.formTemplateId],
        notifyScale: notifyScale,
        unknownHypocenterHoldSeconds: unknownHypocenterHoldSeconds,
        quietHoursStart: quietHoursStart,
        quietHoursEnd: quietHoursEnd,
        p2pquakeApiUrl: CONFIG.p2pquakeApiUrl,
        p2pquakeLimit: CONFIG.p2pquakeLimit,
        lineWorksTokenUrl: CONFIG.lineWorksTokenUrl,
        stateRetentionDays: CONFIG.stateRetentionDays,
        maxNotifiedRecords: CONFIG.maxNotifiedRecords,
        failureNotifySuppressSeconds: CONFIG.failureNotifySuppressSeconds,
        formTitlePrefix: CONFIG.formTitlePrefix,
        expectedRunsPerHour: CONFIG.expectedRunsPerHour,
    };
}

function requireProperty(properties, key) {
    const value = properties.getProperty(key);

    if (value === null || String(value).trim() === "") {
        throw new Error(ERROR_MESSAGES.propertyMissing(key));
    }

    return String(value).trim();
}

function readOptionalProperty(properties, key) {
    const value = properties.getProperty(key);

    return value === null ? "" : String(value).trim();
}

function readIntegerProperty(properties, key, defaultValue) {
    const raw = properties.getProperty(key);

    if (raw === null || String(raw).trim() === "") {
        return defaultValue;
    }

    const text = String(raw).trim();

    if (!/^-?\d+$/.test(text)) {
        throw new Error(ERROR_MESSAGES.propertyNotInteger(key));
    }

    return Number(text);
}
