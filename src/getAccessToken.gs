// LINE WORKS API用アクセストークンを取得するファイル

let cachedAccessToken = null;
let cachedAccessTokenExpiresAt = 0;
let cachedAccessTokenKey = null;

function getAccessToken(properties, config) {
    const cacheNow = Date.now();
    const tokenUrl = resolveLineWorksTokenUrl(properties, config);
    const clientId = properties.getProperty(PROPERTY_KEYS.clientId);
    const cacheKey = tokenUrl + "|" + clientId;

    if (
        cachedAccessToken &&
        cachedAccessTokenKey === cacheKey &&
        cacheNow < cachedAccessTokenExpiresAt
    ) {
        return cachedAccessToken;
    }
    const clientSecret = properties.getProperty(PROPERTY_KEYS.clientSecret);
    const serviceAcct = properties.getProperty(PROPERTY_KEYS.serviceAcct);
    const rawKey = properties.getProperty(PROPERTY_KEYS.privateKey);

    if (!clientId) {
        throw new Error(ERROR_MESSAGES.propertyMissing(PROPERTY_KEYS.clientId));
    }

    if (!clientSecret) {
        throw new Error(ERROR_MESSAGES.propertyMissing(PROPERTY_KEYS.clientSecret));
    }

    if (!serviceAcct) {
        throw new Error(ERROR_MESSAGES.propertyMissing(PROPERTY_KEYS.serviceAcct));
    }

    if (!rawKey) {
        throw new Error(ERROR_MESSAGES.propertyMissing(PROPERTY_KEYS.privateKey));
    }

    const privateKey = normalizePrivateKey(rawKey);
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
        aud: tokenUrl,
    };

    const encodedHeader = Utilities.base64EncodeWebSafe(
        JSON.stringify(header)
    ).replace(/=+$/, "");

    const encodedPayload = Utilities.base64EncodeWebSafe(
        JSON.stringify(payload)
    ).replace(/=+$/, "");

    const signatureInput = `${encodedHeader}.${encodedPayload}`;
    const signature = Utilities.computeRsaSha256Signature(signatureInput, privateKey);
    const encodedSignature = Utilities.base64EncodeWebSafe(signature).replace(/=+$/, "");
    const jwt = `${signatureInput}.${encodedSignature}`;

    const response = UrlFetchApp.fetch(tokenUrl, {
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

    let json = null;

    try {
        json = JSON.parse(text);
    } catch (e) {
        json = null;
    }

    if (code !== 200 || !json || !json.access_token) {
        // レスポンス本文には秘密情報が含まれ得るため、ステータスだけを残す
        throw createHttpError(ERROR_MESSAGES.tokenFailed, code, "");
    }

    cachedAccessToken = json.access_token;
    cachedAccessTokenExpiresAt = cacheNow + 50 * 60 * 1000;
    cachedAccessTokenKey = cacheKey;

    return json.access_token;
}

function resolveLineWorksTokenUrl(properties, config) {
    if (config && config.lineWorksTokenUrl) {
        assertHttpsUrl(PROPERTY_KEYS.lineWorksTokenUrl, config.lineWorksTokenUrl);

        return config.lineWorksTokenUrl;
    }

    const configured = readOptionalProperty(properties, PROPERTY_KEYS.lineWorksTokenUrl);
    const url = configured === "" ? CONFIG.lineWorksTokenUrl : configured;

    assertHttpsUrl(PROPERTY_KEYS.lineWorksTokenUrl, url);

    return url;
}

function normalizePrivateKey(rawKey) {
    let key = String(rawKey || "")
        .replace(/^"|"$/g, "")
        .replace(/\\n/g, "\n")
        .trim();

    const body = key
        .replace(/-----BEGIN PRIVATE KEY-----/g, "")
        .replace(/-----END PRIVATE KEY-----/g, "")
        .replace(/\s+/g, "");

    if (body === "") {
        throw new Error(ERROR_MESSAGES.propertyMissing(PROPERTY_KEYS.privateKey));
    }

    const lines = body.match(/.{1,64}/g);

    if (!lines) {
        throw new Error(ERROR_MESSAGES.propertyMissing(PROPERTY_KEYS.privateKey));
    }

    return `-----BEGIN PRIVATE KEY-----
${lines.join("\n")}
-----END PRIVATE KEY-----`;
}
