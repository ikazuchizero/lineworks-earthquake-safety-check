// LINE WORKSに送る安否確認メッセージを作成するファイル

function createEarthquakeMessage(event, scaleText, formUrl) {
    const hypocenter = event.earthquake.hypocenter?.name || "不明";

    const eventTime = new Date(event.earthquake.time);

    const formattedTime = Utilities.formatDate(eventTime, "Asia/Tokyo", "yyyy/MM/dd HH:mm:ss");

    return `お疲れ様です。

先ほど発生した地震について安否確認を実施いたします。

【地震情報】
・発生時刻：${formattedTime}
・震源地：${hypocenter}
・最大震度：${scaleText}

以下のフォームより回答をお願いいたします。

${formUrl}

余震の可能性もありますので、引き続き安全確保をお願いいたします。`;
}
