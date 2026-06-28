<?php
declare(strict_types=1);

final class StateStore
{
    // state.json は「どの地震を通知済みにしたか」を保持するファイル。
    // dedupe_key を保存し、同じ地震の二重通知を防ぐために使う。
    private string $path;

    /** @var array<string, mixed>|null */
    private ?array $state = null;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function has(string $dedupeKey): bool
    {
        // stateに存在するdedupe_keyは通知済みとして扱う。
        // event.idではなく、EarthquakeCheckerで生成した地震単位のキーを見る。
        $state = $this->load();

        return isset($state['notified'][$dedupeKey]);
    }

    /** @param array<string, mixed> $record */
    public function markNotified(string $dedupeKey, array $record): void
    {
        // LINE WORKS送信成功後にだけ呼ぶこと。
        // 送信前に通知済みにすると、送信失敗時に通知漏れが起きる。
        $this->load();
        $record['dedupe_key'] = $dedupeKey;
        $this->state['notified'][$dedupeKey] = $record;
    }

    public function save(): void
    {
        // state.json は一時ファイルへ書いてからrenameで置き換える。
        // 直接上書きして途中で止まると、不正JSONになり再通知事故につながる。
        $this->load();

        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new RuntimeException('Failed to create state directory: ' . $dir);
        }

        $json = json_encode($this->state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if ($json === false) {
            throw new RuntimeException('Failed to encode state JSON: ' . json_last_error_msg());
        }

        $tmpPath = $this->path . '.' . getmypid() . '.tmp';

        if (file_put_contents($tmpPath, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write temporary state file: ' . $tmpPath);
        }

        if (!rename($tmpPath, $this->path)) {
            @unlink($tmpPath);
            throw new RuntimeException('Failed to replace state file: ' . $this->path);
        }
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        // 既存stateが不正JSONなら空扱いせず停止する。
        // 空扱いすると過去の通知済み情報が消え、同じ地震を再通知する可能性がある。
        if ($this->state !== null) {
            return $this->state;
        }

        if (!is_file($this->path)) {
            $this->state = [
                'notified' => [],
            ];
            return $this->state;
        }

        $contents = file_get_contents($this->path);

        if ($contents === false) {
            throw new RuntimeException('Failed to read state file: ' . $this->path);
        }

        $state = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('State JSON is invalid: ' . json_last_error_msg());
        }

        if (!is_array($state) || !isset($state['notified']) || !is_array($state['notified'])) {
            throw new RuntimeException('State file schema is invalid: ' . $this->path);
        }

        $this->state = $state;

        return $this->state;
    }
}
