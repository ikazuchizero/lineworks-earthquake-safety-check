// 初回セットアップ用: スクリプトプロパティを一括登録するファイル
//
// 使い方:
//   1. 下の SETUP_VALUES を自分の値に書き換えて保存（Ctrl+S）
//   2. 関数 setAllProperties を実行
//   3. 実行ログの「設定したキー」を確認
//   4. 秘密情報がソースに残らないよう、このファイルを削除する
//      （プロパティはサーバー側に残るため、削除しても動作に影響しない）
//
// PRIVATE_KEY は改行を消して1行で貼り付ける:
//   "-----BEGIN PRIVATE KEY-----MIIE...-----END PRIVATE KEY-----"
//   ヘッダーは付けても外してもよい（normalizePrivateKey が除去して64文字で組み直す）
//
// 固定URL方式を使う場合は FORM_TEMPLATE_ID を消して FIXED_FORM_URL を設定する

const SETUP_VALUES = {
    SPREADSHEET_ID: "",
    CLIENT_ID: "",
    CLIENT_SECRET: "",
    SERVICE_ACCT: "",
    BOT_ID: "",
    ROOM_ID: "",
    MAINTENANCE_ROOM_ID: "",
    PRIVATE_KEY: "",
    // 0 はテスト専用。本番は 40（震度4以上）など
    NOTIFY_SCALE: "",
    FORM_TEMPLATE_ID: "",
    // FIXED_FORM_URL: "",
};

// 空欄のキーはスキップする（後から個別に追加できる）
function setAllProperties() {
    const values = {};
    const skipped = [];

    Object.keys(SETUP_VALUES).forEach(function (key) {
        const raw = SETUP_VALUES[key];
        const value =
            raw === null || raw === undefined ? "" : String(raw).trim();

        if (value === "") {
            skipped.push(key);
            return;
        }

        values[key] = value;
    });

    if (Object.keys(values).length === 0) {
        throw new Error(
            "SETUP_VALUES がすべて空です。値を入れてから実行してください"
        );
    }

    const props = PropertiesService.getScriptProperties();
    props.setProperties(values, false); // false = 既存の他のキーは消さない

    console.log("設定したキー: " + Object.keys(values).sort().join(", "));

    if (skipped.length > 0) {
        console.log("未入力のためスキップ: " + skipped.sort().join(", "));
    }

    console.log(
        "現在のキー: " +
            Object.keys(props.getProperties()).sort().join(", ")
    );
}

// 通知先ルームの channelId を調べる（ROOM_ID が不明なとき用）
function diagnoseBot() {
    const props = PropertiesService.getScriptProperties();
    const clientId = props.getProperty("CLIENT_ID");
    const clientSecret = props.getProperty("CLIENT_SECRET");
    const serviceAcct = props.getProperty("SERVICE_ACCT");
    const botId = props.getProperty("BOT_ID");
    const key = normalizePrivateKey(props.getProperty("PRIVATE_KEY"));
    const tokenUrl = "https://auth.worksmobile.com/oauth2/v2.0/token";
    const now = Math.floor(Date.now() / 1000);
    const b64 = function (value) {
        return Utilities.base64EncodeWebSafe(value).replace(/=+$/, "");
    };

    const input =
        b64(JSON.stringify({ alg: "RS256", typ: "JWT" })) +
        "." +
        b64(
            JSON.stringify({
                iss: clientId,
                sub: serviceAcct,
                iat: now,
                exp: now + 3600,
                aud: tokenUrl,
            })
        );
    const jwt = input + "." + b64(Utilities.computeRsaSha256Signature(input, key));

    const res = UrlFetchApp.fetch(tokenUrl, {
        method: "post",
        muteHttpExceptions: true,
        payload: {
            grant_type: "urn:ietf:params:oauth:grant-type:jwt-bearer",
            assertion: jwt,
            client_id: clientId,
            client_secret: clientSecret,
            scope: "bot",
        },
    });

    console.log("token HTTP=" + res.getResponseCode());

    if (res.getResponseCode() !== 200) {
        console.log(res.getContentText());
        return;
    }

    const token = JSON.parse(res.getContentText()).access_token;
    const headers = { Authorization: "Bearer " + token };

    const bot = UrlFetchApp.fetch(
        "https://www.worksapis.com/v1.0/bots/" + encodeURIComponent(botId),
        { headers: headers, muteHttpExceptions: true }
    );
    console.log("bot HTTP=" + bot.getResponseCode());

    const channels = UrlFetchApp.fetch(
        "https://www.worksapis.com/v1.0/bots/" +
            encodeURIComponent(botId) +
            "/channels",
        { headers: headers, muteHttpExceptions: true }
    );
    console.log("channels HTTP=" + channels.getResponseCode());

    if (channels.getResponseCode() !== 200) {
        console.log(channels.getContentText());
        return;
    }

    const list = JSON.parse(channels.getContentText()).channels || [];

    if (list.length === 0) {
        console.log("Bot が参加しているルームがありません（招待を確認）");
        return;
    }

    list.forEach(function (channel) {
        console.log("- " + channel.channelId + " | " + (channel.title || ""));
    });
}

// 必須キーが入っているかを、値を出さずに確認する
function showPropertyStatus() {
    const requiredKeys = [
        "SPREADSHEET_ID",
        "CLIENT_ID",
        "CLIENT_SECRET",
        "SERVICE_ACCT",
        "PRIVATE_KEY",
        "BOT_ID",
        "ROOM_ID",
        "NOTIFY_SCALE",
    ];
    const props = PropertiesService.getScriptProperties().getProperties();

    requiredKeys.forEach(function (key) {
        const value = props[key];
        console.log(
            value
                ? "OK   " + key + " (" + String(value).length + "文字)"
                : "MISS " + key
        );
    });

    const formMode = props.FIXED_FORM_URL
        ? "FIXED_FORM_URL"
        : props.FORM_TEMPLATE_ID
          ? "FORM_TEMPLATE_ID"
          : "未設定";

    console.log("通知フォーム: " + formMode);
    console.log(
        "MAINTENANCE_ROOM_ID: " +
            (props.MAINTENANCE_ROOM_ID ? "設定あり" : "未設定（ROOM_IDへ送信）")
    );
}
