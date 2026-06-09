// テスト用に最終通知済み地震IDを削除するファイル

function resetLastNotifiedId() {
    PropertiesService.getScriptProperties().deleteProperty("LAST_NOTIFIED_ID");
}
