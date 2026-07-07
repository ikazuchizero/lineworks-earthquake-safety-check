<?php
declare(strict_types=1);

final class ErrorNotificationStore
{
    private string $path;

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function shouldNotify(string $fingerprint, int $intervalSeconds): bool
    {
        // 本体の state.json は「地震を通知済みか」を管理する。
        // このファイルはそれとは別に、処理失敗チャット通知の連投抑止だけを管理する。
        $data = $this->load();
        $lastFingerprint = (string) ($data['last_error_fingerprint'] ?? '');
        $lastNotifiedAt = (string) ($data['last_error_notified_at'] ?? '');
        $lastTimestamp = strtotime($lastNotifiedAt);

        if ($lastFingerprint !== $fingerprint || $lastTimestamp === false) {
            return true;
        }

        return (time() - $lastTimestamp) >= $intervalSeconds;
    }

    public function markNotified(string $fingerprint): void
    {
        // fingerprint は「同じエラーを短時間に何度も送らない」ための一時的な抑止キー。
        // 恒久的な障害分類ではないため、原因調査は app.log 側で行う。
        $this->load();
        $this->data['last_error_fingerprint'] = $fingerprint;
        $this->data['last_error_notified_at'] = gmdate('c');
        $this->save();
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        // このJSONが壊れている場合は、連投抑止が効かない状態で進めず例外にする。
        // 失敗通知用stateであり、本体の通知済みstateとは混ぜない。
        if ($this->data !== null) {
            return $this->data;
        }

        if (!is_file($this->path)) {
            $this->data = [
                'last_error_fingerprint' => null,
                'last_error_notified_at' => null,
            ];
            return $this->data;
        }

        $contents = file_get_contents($this->path);
        if ($contents === false) {
            throw new RuntimeException('Failed to read error notification state.');
        }

        $data = json_decode($contents, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new RuntimeException('Error notification state JSON is invalid.');
        }

        $this->data = $data;

        return $this->data;
    }

    private function save(): void
    {
        // 失敗通知の抑止状態もtmp経由で保存する。
        // 壊れたJSONを残すと、次回以降に同じエラー通知が連投される可能性があるため。
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new RuntimeException('Failed to create error notification state directory.');
        }

        $json = json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('Failed to encode error notification state JSON.');
        }

        $tmpPath = $this->path . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmpPath, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write error notification state.');
        }

        if (!rename($tmpPath, $this->path)) {
            @unlink($tmpPath);
            throw new RuntimeException('Failed to replace error notification state.');
        }
    }
}
