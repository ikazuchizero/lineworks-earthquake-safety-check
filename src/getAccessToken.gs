// LINE WORKS API用アクセストークンを取得するファイル

function getAccessToken(properties) {
    const clientId = properties.getProperty("CLIENT_ID");
    const clientSecret = properties.getProperty("CLIENT_SECRET");
    const serviceAcct = properties.getProperty("SERVICE_ACCT");

    let rawKey = properties.getProperty("PRIVATE_KEY");

    if (!clientId) {
        throw new Error(ERROR_MESSAGES.clientIdMissing);
    }

    if (!clientSecret) {
        throw new Error(ERROR_MESSAGES.clientSecretMissing);
    }

    if (!serviceAcct) {
        throw new Error(ERROR_MESSAGES.serviceAcctMissing);
    }

    if (!rawKey) {
        throw new Error(ERROR_MESSAGES.privateKeyMissing);
    }

    rawKey = rawKey.replace(/^"|"$/g, "").trim();

    const cleanedKey = rawKey
        .replace(/-----BEGIN PRIVATE KEY-----/, "")
        .replace(/-----END PRIVATE KEY-----/, "")
        .replace(/\s+/g, "")
        .match(/.{1,64}/g)
        .join("\n");

    const privateKey = `-----BEGIN PRIVATE KEY-----
${cleanedKey}
-----END PRIVATE KEY-----`;

    const now = Math.floor(Date.now() / 1000);

    const header = {
        alg: "RS256",
        typ: "JWT",
    };

    const payload = {
        iss: clientId,
        sub: serviceAcct,
        iat: now,
        exp: now + 3600,
        aud: LINEWORKS_TOKEN_URL,
    };

    const encodedHeader = Utilities.base64EncodeWebSafe(JSON.stringify(header)).replace(/=+$/, "");

    const encodedPayload = Utilities.base64EncodeWebSafe(JSON.stringify(payload)).replace(/=+$/, "");

    const signatureInput = `${encodedHeader}.${encodedPayload}`;

    const signature = Utilities.computeRsaSha256Signature(signatureInput, privateKey);

    const encodedSignature = Utilities.base64EncodeWebSafe(signature).replace(/=+$/, "");

    const jwt = `${signatureInput}.${encodedSignature}`;

    const response = UrlFetchApp.fetch(LINEWORKS_TOKEN_URL, {
        method: "post",
        payload: {
            grant_type: "urn:ietf:params:oauth:grant-type:jwt-bearer",
            assertion: jwt,
            client_id: clientId,
            client_secret: clientSecret,
            scope: "bot.message",
        },
        muteHttpExceptions: true,
    });

    const code = response.getResponseCode();
    const text = response.getContentText();
    const json = JSON.parse(text);

    if (code !== 200 || !json.access_token) {
        throw createHttpError(ERROR_MESSAGES.tokenFailed, code, text);
    }

    return json.access_token;
}
