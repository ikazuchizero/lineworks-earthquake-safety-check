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

        const formId = copyFormTemplate(target, context.config);
        record = {
            formId: formId,
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

function copyFormTemplate(target, config) {
    try {
        const templateFile = DriveApp.getFileById(config.formTemplateId);
        const name =
            config.formTitlePrefix + " " + buildFormName(target.earthquakeTime);

        return templateFile.makeCopy(name).getId();
    } catch (e) {
        throw new Error(ERROR_MESSAGES.formCopyFailed + ": " + e.message);
    }
}

// Driveのファイル名に使えない文字を避ける
function buildFormName(earthquakeTime) {
    const date = parseTokyoTime(earthquakeTime);

    if (!date) {
        return String(earthquakeTime).replace(/[\/\\:]/g, "-");
    }

    return Utilities.formatDate(date, "Asia/Tokyo", "yyyyMMdd-HHmmss");
}
