// 初期設定と配置を確認するファイル

function setupCheck() {
    const properties = PropertiesService.getScriptProperties();
    const results = [];
    let config = null;

    try {
        config = loadConfig(properties);
        results.push(okResult("config_values"));
    } catch (e) {
        results.push(ngResult("config_values", e.message));
    }

    try {
        const spreadsheet = getSpreadsheet(properties);
        results.push(okResult("spreadsheet"));

        getOrCreateSheet(spreadsheet, SHEET_NAMES.log, LOG_HEADERS);
        getOrCreateSheet(spreadsheet, SHEET_NAMES.state, STATE_HEADERS);
        getOrCreateSheet(spreadsheet, SHEET_NAMES.health, HEALTH_HEADERS);
        results.push(okResult("sheets"));
    } catch (e) {
        results.push(ngResult("sheets", e.message));
    }

    if (config) {
        if (config.fixedFormUrl) {
            // 固定URLモードではテンプレートを要求しない
            results.push(okResult("form_fixed_url"));
        } else {
            try {
                const template = FormApp.openById(config.formTemplateId);

                if (!template.supportsAdvancedResponderPermissions()) {
                    throw new Error(ERROR_MESSAGES.templateNotSupported);
                }

                if (template.collectsEmail()) {
                    throw new Error(ERROR_MESSAGES.templateCollectsEmail);
                }

                results.push(okResult("form_template"));
            } catch (e) {
                results.push(ngResult("form_template", e.message));
            }
        }

        try {
            Utilities.computeRsaSha256Signature(
                "setup-check",
                normalizePrivateKey(config.privateKey)
            );
            results.push(okResult("private_key"));
        } catch (e) {
            results.push(ngResult("private_key", e.message));
        }
    }

    try {
        const triggers = ScriptApp.getProjectTriggers();
        const checkTriggers = triggers.filter(function (trigger) {
            return trigger.getHandlerFunction() === "checkAndNotifyEarthquake";
        });
        const healthTriggers = triggers.filter(function (trigger) {
            return trigger.getHandlerFunction() === "writeHourlyHealthCheck";
        });

        if (checkTriggers.length !== 1 || healthTriggers.length !== 1) {
            results.push(
                ngResult(
                    "triggers",
                    "各トリガーが1つだけになるよう setupTriggers() を実行してください（現在: check=" +
                        checkTriggers.length +
                        ", health=" +
                        healthTriggers.length +
                        "）"
                )
            );
        } else {
            // GAS APIでは実行間隔を取得できないため、setupTriggers が保存した値で確認する
            const checkMinutes = properties.getProperty(
                PROPERTY_KEYS.checkIntervalMinutes
            );
            const healthHours = properties.getProperty(
                PROPERTY_KEYS.healthIntervalHours
            );

            if (checkMinutes === "1" && healthHours === "1") {
                results.push(okResult("triggers"));
            } else {
                results.push(
                    ngResult(
                        "triggers",
                        "実行間隔の記録がありません。setupTriggers() を再実行してください"
                    )
                );
            }
        }
    } catch (e) {
        results.push(ngResult("triggers", e.message));
    }

    for (const result of results) {
        if (result.status === "OK") {
            console.log("OK " + result.name);
        } else {
            console.error("NG " + result.name + ": " + result.reason);
        }
    }

    const allOk = results.every(function (result) {
        return result.status === "OK";
    });

    console.log(
        allOk ? "セットアップ確認: すべてOK" : "セットアップ確認: NGがあります"
    );

    return allOk;
}

function okResult(name) {
    return { name: name, status: "OK", reason: "" };
}

function ngResult(name, reason) {
    return { name: name, status: "NG", reason: reason };
}
