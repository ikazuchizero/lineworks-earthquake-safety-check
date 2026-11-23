// Googleフォームの複製・公開・回答者URL取得を管理するファイル

let checkedTemplateId = null;

function resolveFormForNotification(context, state, target) {
    const earthquakeTime = target.earthquakeTime;
    let record = state.forms[earthquakeTime];

    if (record && record.responderUrl) {
        return record.responderUrl;
    }

    if (!record || !record.formId) {
        assertTemplateCanPublish(context.config);

        const formName = buildFormName(target.earthquakeTime, context.config);
        const formId = copyFormTemplate(formName, context.config);
        record = {
            formId: formId,
            formName: formName,
            createdAt: new Date().toISOString(),
        };
        state.forms[earthquakeTime] = record;
        // 公開前にコピーIDを保存し、再実行で別フォームを作らない
        saveState(context, state);
    }

    const form = FormApp.openById(record.formId);

    if (!form.supportsAdvancedResponderPermissions()) {
        throw new Error(ERROR_MESSAGES.templateNotSupported);
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

    record.responderUrl = responderUrl;
    state.forms[earthquakeTime] = record;
    // 送信前に回答者URLを保存し、再実行でも同じフォームを使う
    saveState(context, state);

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

// 地震ごとに決まる一意な名前。コピー直後に中断しても、次回この名前で再利用する。
function buildFormName(earthquakeTime, config) {
    const prefix = (config && config.formTitlePrefix) || CONFIG.formTitlePrefix;
    const date = parseTokyoTime(earthquakeTime);

    if (!date) {
        return (
            prefix +
            " " +
            String(earthquakeTime).replace(/[\/\\:]/g, "-")
        );
    }

    return (
        prefix +
        " " +
        Utilities.formatDate(date, "Asia/Tokyo", "yyyyMMdd-HHmmss")
    );
}

function copyFormTemplate(formName, config) {
    try {
        // makeCopy 成功後に状態保存が失敗しても、次回は同じ名前のコピーを使う
        const existingFormId = findExistingFormId(formName);

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

function findExistingFormId(formName) {
    const files = DriveApp.getFilesByName(formName);
    let oldest = null;

    while (files.hasNext()) {
        const file = files.next();

        if (file.getMimeType() !== MimeType.GOOGLE_FORMS) {
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
