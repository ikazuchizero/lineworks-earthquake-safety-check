<?php
declare(strict_types=1);

final class ConnectivityChecker
{
    // 初回設置時にLINE WORKS認証と送信先到達だけを確認する。
    // 安否確認本文とは別の短い文面にして、利用者が実災害通知と誤認しないようにする。
    private LineWorksClient $lineWorksClient;
    private int $quietHoursStart;
    private int $quietHoursEnd;

    public function __construct(LineWorksClient $lineWorksClient, int $quietHoursStart = 0, int $quietHoursEnd = 6)
    {
        $this->lineWorksClient = $lineWorksClient;
        $this->quietHoursStart = $quietHoursStart;
        $this->quietHoursEnd = $quietHoursEnd;
    }

    public function run(): void
    {
        if (!$this->isQuietNow()) {
            // 第2引数を渡さないことで、LineWorksClient側の通常 room_id へ送る。
            $this->lineWorksClient->sendMessage('安否確認bot 通知用チャット疎通確認です。地震通知ではありません。');
        }

    }

    private function isQuietNow(): bool
    {
        $hour = (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('G');

        return $this->quietHoursStart < $this->quietHoursEnd
            ? $hour >= $this->quietHoursStart && $hour < $this->quietHoursEnd
            : $hour >= $this->quietHoursStart || $hour < $this->quietHoursEnd;
    }
}
