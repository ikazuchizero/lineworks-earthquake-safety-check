<?php
declare(strict_types=1);

final class ConnectivityChecker
{
    // 初回設置時にLINE WORKS認証と送信先到達だけを確認する。
    // 安否確認本文とは別の短い文面にして、利用者が実災害通知と誤認しないようにする。
    private LineWorksClient $lineWorksClient;
    private ?string $maintenanceRoomId;

    public function __construct(LineWorksClient $lineWorksClient, ?string $maintenanceRoomId)
    {
        $this->lineWorksClient = $lineWorksClient;
        $this->maintenanceRoomId = $maintenanceRoomId;
    }

    public function run(): void
    {
        // 通常の安否確認通知先は必ず確認する。
        // 第2引数を渡さないことで、LineWorksClient側の通常 room_id へ送る。
        $this->lineWorksClient->sendMessage('安否確認bot 通知用チャット疎通確認です。地震通知ではありません。');

        if ($this->maintenanceRoomId === null) {
            return;
        }

        // フォームストック有効時は、低在庫・枯渇・失敗通知を受ける保守通知先も確認する。
        $this->lineWorksClient->sendMessage(
            '安否確認bot 保守通知用チャット疎通確認です。地震通知ではありません。',
            $this->maintenanceRoomId
        );
    }
}
