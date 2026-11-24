// Googleフォームの複製・公開・回答者URL取得を管理するファイル

let checkedTemplateId = null;

function resolveFormForNotification(context, state, target) {
    const earthquakeTime = target.earthquakeTime;
    let record = state.forms[earthquakeTime];
    let failedFormId = null;
    let form = null;

    if (record && record.formId) {
        try {
            form = FormApp.openById(record.formId);
        } catch (firstError) {
            // 一時的なAPIエラーと削除を区別するため、一度だけ再試行する
            try {
                Utilities.sleep(500);
                form = FormApp.openById(record.formId);
            } catch (e) {
                // フォームが削除・ゴミ箱移動された場合は、状態を捨てて作り直す。
                // これをしないと同じフォームIDで永久に失敗し続ける。
                console.error(
                    "保存済みフォームを開けないため再作成します: " +
                        e.message +
                        "（初回: " +
                        firstError.message +
                        "）"
                );
                failedFormId = record.formId;
                delete state.forms[earthquakeTime];
                markStateChanged(state);
                record = null;
                form = null;
            }
        }
    }

    if (!form) {
        assertTemplateCanPublish(context.config);

        const formName = buildFormName(target.hypocenterName, context.config);
        const usedFormIds = {};
        Object.keys(state.forms).forEach(function (key) {
            const saved = state.forms[key];
            if (saved && saved.formId) {
                usedFormIds[String(saved.formId)] = true;
            }
        });
        const formId = copyFormTemplate(
            formName,
            context.config,
            failedFormId,
            usedFormIds
        );
        record = {
            formId: formId,
            formName: formName,
            createdAt: new Date().toISOString(),
        };
        state.forms[earthquakeTime] = record;
        markStateChanged(state);
        // 公開前にコピーIDを保存し、再実行で別フォームを作らない
        saveState(context, state);

        form = FormApp.openById(record.formId);
    }

    if (!form.supportsAdvancedResponderPermissions()) {
        throw new Error(ERROR_MESSAGES.templateNotSupported);
    }

    if (form.collectsEmail()) {
        throw new Error(ERROR_MESSAGES.templateCollectsEmail);
    }

    if (!form.isPublished()) {
        form.setPublished(true);
    }

    if (!form.isAcceptingResponses()) {
        form.setAcceptingResponses(true);
    }

    const responderUrl = form.getPublishedUrl();

    if (!responderUrl) {
        throw new Error(ERROR_MESSAGES.formUrlMissing);
    }

    if (record.responderUrl !== responderUrl) {
        record.responderUrl = responderUrl;
        record.updatedAt = new Date().toISOString();
        state.forms[earthquakeTime] = record;
        markStateChanged(state);
        // 送信前に回答者URLを保存し、再実行でも同じフォームを使う
        saveState(context, state);
    }

    return responderUrl;
}

function assertTemplateCanPublish(config) {
    if (checkedTemplateId === config.formTemplateId) {
        return;
    }

    const template = FormApp.openById(config.formTemplateId);

    if (!template.supportsAdvancedResponderPermissions()) {
        throw new Error(ERROR_MESSAGES.templateNotSupported);
    }

    if (template.collectsEmail()) {
        throw new Error(ERROR_MESSAGES.templateCollectsEmail);
    }

    checkedTemplateId = config.formTemplateId;
}

// 地震ごとのフォーム名。震源地だけにして、Drive 上で見分けやすくする。
// 同じ震源地の地震が起きると同名になるため、複製の再利用時は
// 他の地震に割り当て済みのフォームを避ける（copyFormTemplate 側で判定）。
function buildFormName(hypocenterName, config) {
    const prefix = (config && config.formTitlePrefix) || CONFIG.formTitlePrefix;

    return prefix + " " + formatHypocenterForFormName(hypocenterName);
}

function formatHypocenterForFormName(value) {
    const name = String(value === null || value === undefined ? "" : value).trim();

    if (name === "" || name === "UNKNOWN") {
        return "震源不明";
    }

    return name;
}

function copyFormTemplate(formName, config, excludeFormId, usedFormIds) {
    try {
        // makeCopy 成功後に状態保存が失敗しても、次回は同じ名前のコピーを使う。
        // ただし、直前に開けなかったフォームIDと、他の地震に割り当て済みのフォームは再利用しない。
        const existingFormId = findExistingFormId(
            formName,
            excludeFormId,
            usedFormIds
        );

        if (existingFormId) {
            console.log("中断前のフォームコピーを再利用します: " + formName);
            return existingFormId;
        }

        const templateFile = DriveApp.getFileById(config.formTemplateId);

        return templateFile.makeCopy(formName).getId();
    } catch (e) {
        throw new Error(ERROR_MESSAGES.formCopyFailed + ": " + e.message);
    }
}

function findExistingFormId(formName, excludeFormId, usedFormIds) {
    const files = DriveApp.getFilesByName(formName);
    const used = usedFormIds || {};
    let oldest = null;

    while (files.hasNext()) {
        const file = files.next();

        if (file.getMimeType() !== MimeType.GOOGLE_FORMS) {
            continue;
        }

        if (excludeFormId && String(file.getId()) === String(excludeFormId)) {
            continue;
        }

        if (used[String(file.getId())]) {
            continue;
        }

        if (
            !oldest ||
            file.getDateCreated().getTime() < oldest.getDateCreated().getTime()
        ) {
            oldest = file;
        }
    }

    return oldest ? oldest.getId() : null;
}
