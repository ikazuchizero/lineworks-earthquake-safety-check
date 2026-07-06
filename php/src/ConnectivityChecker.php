<?php
declare(strict_types=1);

final class ConnectivityChecker
{
    private LineWorksClient $lineWorksClient;
    private ?string $roomId;

    public function __construct(LineWorksClient $lineWorksClient, ?string $roomId)
    {
        $this->lineWorksClient = $lineWorksClient;
        $this->roomId = $roomId;
    }

    public function run(): void
    {
        // 初回設置時のLINE WORKS疎通だけを確認する。
        // 安否確認本文や地震情報は送らず、短い検証メッセージだけを送る。
        $this->lineWorksClient->sendMessage(
            '安否確認bot 疎通確認です。地震通知ではありません。',
            $this->roomId
        );
    }
}
