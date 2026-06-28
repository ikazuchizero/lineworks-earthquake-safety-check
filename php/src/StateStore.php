<?php
declare(strict_types=1);

final class StateStore
{
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

    /** @param array<string, mixed> $record */
    public function markNotified(string $dedupeKey, array $record): void
    {
        $this->load();
        $record['dedupe_key'] = $dedupeKey;
        $this->state['notified'][$dedupeKey] = $record;
    }

    public function save(): void
    {
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
