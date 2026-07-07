<?php
declare(strict_types=1);

final class FailureNotifier
{
    // check.php の致命的な処理失敗を、保守通知先へ短く知らせるためのクラス。
    // 通知本文には分類とログ確認依頼だけを入れ、例外詳細や秘匿値を広げない。
    private const SUPPRESS_SECONDS = 3600;

    private LineWorksClient $lineWorksClient;
    private ErrorNotificationStore $store;
    private ?string $roomId;

    public function __construct(LineWorksClient $lineWorksClient, ErrorNotificationStore $store, ?string $roomId)
    {
        $this->lineWorksClient = $lineWorksClient;
        $this->store = $store;
        $this->roomId = $roomId;
    }

    public function notify(Throwable $error, string $category): bool
    {
        // 同じエラーをcronごとに連投しないため、ErrorNotificationStoreのfingerprintで抑止する。
        // LINE WORKSそのものの失敗は再帰通知になりやすいため、ここでは送らない。
        // LINE WORKS自体の失敗をLINE WORKSへ通知しようとすると、同じ失敗を繰り返すだけになる。
        // その場合はapp.logに任せ、チャット通知の再帰を避ける。
        if (str_contains($error->getMessage(), 'LINE WORKS')) {
            return false;
        }

        $fingerprint = hash('sha256', $category . '|' . get_class($error) . '|' . $error->getMessage());
        if (!$this->store->shouldNotify($fingerprint, self::SUPPRESS_SECONDS)) {
            return false;
        }

        $this->lineWorksClient->sendMessage(
            '安否確認botで処理失敗が発生しました。分類: ' . $category . '。app.logを確認してください。',
            $this->roomId
        );
        $this->store->markNotified($fingerprint);

        return true;
    }
}
