<?php
declare(strict_types=1);

final class StateStore
{
    // state.json は「どの地震を通知済みにしたか」と、UNKNOWN震源地の保留状態を保持する。
    // dedupe_keyだけでなく earthquake_time 単位でも通知済みを見て、UNKNOWN→震源地あり続報の二重通知を防ぐ。
    private string $path;

    /** @var array<string, mixed>|null */
    private ?array $state = null;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function has(string $dedupeKey): bool
    {
        $state = $this->load();

        return isset($state['notified'][$dedupeKey]);
    }

    public function hasNotifiedEarthquakeTime(string $earthquakeTime): bool
    {
        $state = $this->load();

        return isset($state['notified_by_earthquake_time'][$earthquakeTime]);
    }

    /** @param array<string, mixed> $record */
    public function markNotified(string $dedupeKey, array $record): void
    {
        // LINE WORKS送信成功後にだけ呼ぶこと。
        // 送信前に通知済みにすると、送信失敗時に通知漏れになる。
        $this->load();
        $record['dedupe_key'] = $dedupeKey;
        $this->state['notified'][$dedupeKey] = $record;
    }

    /** @param array<string, mixed> $record */
    public function markNotifiedByEarthquakeTime(string $earthquakeTime, array $record): void
    {
        // UNKNOWNで通知した後に震源地あり続報が来ても、同じ発生時刻なら再通知しないための索引。
        $this->load();
        $this->state['notified_by_earthquake_time'][$earthquakeTime] = $record;
    }

    /** @return array<string, mixed>|null */
    public function getPendingUnknown(string $earthquakeTime): ?array
    {
        $state = $this->load();
        $record = $state['pending_unknown_by_earthquake_time'][$earthquakeTime] ?? null;

        return is_array($record) ? $record : null;
    }

    /** @return array<string, array<string, mixed>> */
    public function pendingUnknowns(): array
    {
        $state = $this->load();
        $pendingUnknowns = [];

        foreach ($state['pending_unknown_by_earthquake_time'] as $earthquakeTime => $record) {
            if (!is_string($earthquakeTime) || !is_array($record)) {
                continue;
            }

            $pendingUnknowns[$earthquakeTime] = $record;
        }

        return $pendingUnknowns;
    }

    /** @param array<string, mixed> $record */
    public function markPendingUnknown(string $earthquakeTime, array $record): void
    {
        // UNKNOWN震源地は即通知せず、一定時間だけ震源地名ありの続報を待つ。
        // pending保存だけではフォームURLを消費しない。
        $this->load();
        $this->state['pending_unknown_by_earthquake_time'][$earthquakeTime] = $record;
    }

    public function removePendingUnknown(string $earthquakeTime): void
    {
        $this->load();
        unset($this->state['pending_unknown_by_earthquake_time'][$earthquakeTime]);
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
            $this->state = $this->defaultState();
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

        // 後方互換: 既存の notified だけを持つstate.jsonも正常に読み込む。
        if (!isset($state['notified_by_earthquake_time']) || !is_array($state['notified_by_earthquake_time'])) {
            $state['notified_by_earthquake_time'] = [];
        }

        if (!isset($state['pending_unknown_by_earthquake_time']) || !is_array($state['pending_unknown_by_earthquake_time'])) {
            $state['pending_unknown_by_earthquake_time'] = [];
        }

        // Backfill earthquake_time index from existing notified records.
        // 既存state.jsonが notified だけを持っていても、過去通知済みの同一発生時刻を再通知しないため。
        foreach ($state['notified'] as $dedupeKey => $record) {
            if (!is_array($record)) {
                continue;
            }

            $earthquakeTime = trim((string) ($record['earthquake_time'] ?? ''));
            if ($earthquakeTime === '' && is_string($dedupeKey) && strpos($dedupeKey, '|') !== false) {
                $earthquakeTime = trim((string) strstr($dedupeKey, '|', true));
            }

            if ($earthquakeTime === '' || isset($state['notified_by_earthquake_time'][$earthquakeTime])) {
                continue;
            }

            $state['notified_by_earthquake_time'][$earthquakeTime] = [
                'dedupe_key' => (string) ($record['dedupe_key'] ?? $dedupeKey),
                'notified_at' => (string) ($record['notified_at'] ?? ''),
            ];
        }

        $this->state = $state;

        return $this->state;
    }

    /** @return array<string, mixed> */
    private function defaultState(): array
    {
        return [
            'notified' => [],
            'notified_by_earthquake_time' => [],
            'pending_unknown_by_earthquake_time' => [],
        ];
    }
}
