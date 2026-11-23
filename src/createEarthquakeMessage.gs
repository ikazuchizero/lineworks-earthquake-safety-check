// LINE WORKSに送る安否確認メッセージを作成するファイル

function createEarthquakeMessage(target, formUrl) {
    const formattedTime = formatEarthquakeTime(target.earthquakeTime);
    const hypocenterName = target.hypocenterName || "不明";
    const scale = scaleText(target.maxScale);

    return `お疲れ様です。

先ほど発生した地震について安否確認を実施いたします。

【地震情報】
・発生時刻：${formattedTime}
・震源地：${hypocenterName}
・最大震度：${scale}

以下のフォームより回答をお願いいたします。

${formUrl}

余震の可能性もありますので、引き続き安全確保をお願いいたします。`;
}
