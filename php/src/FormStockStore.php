<?php
declare(strict_types=1);

final class FormImportException extends RuntimeException
{
}

final class FormStockStore
{
    // forms.json is the operational source of truth for safety-confirmation forms.
    // Each URL must be used at most once. Do not log or print URL values because the
    // links are real response forms and leaking them can expose or corrupt responses.
    private string $path;
    private string $importCsvPath;
    private string $processedDir;
    private string $failedDir;

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    public function __construct(string $path, string $importCsvPath, string $processedDir, string $failedDir)
    {
        $this->path = $path;
        $this->importCsvPath = $importCsvPath;
        $this->processedDir = $processedDir;
        $this->failedDir = $failedDir;
    }

    /** @return array{processed: bool, imported: int, duplicate_skipped: int, invalid_rows: int} */
    public function importCsvIfExists(): array
    {
        // Non-engineers replenish forms by uploading storage/import/forms.csv.
        // Successful imports are archived under processed/; failed imports are moved
        // to failed/ so the uploaded file is not retried forever without inspection.
        if (!is_file($this->importCsvPath)) {
            return [
                'processed' => false,
                'imported' => 0,
                'duplicate_skipped' => 0,
                'invalid_rows' => 0,
            ];
        }

        try {
            $result = $this->importCsv();
            $this->save();
            $this->moveImportFile($this->processedDir);

            return $result;
        } catch (FormImportException $e) {
            $this->moveImportFile($this->failedDir);
            throw $e;
        }
    }

    /** @return array{index: int, url: string}|null */
    public function takeAvailable(): ?array
    {
        // Only available forms can be selected. Used forms are never returned, even
        // if the same URL later appears in an uploaded CSV.
        $data = $this->load();

        foreach ($data['forms'] as $index => $form) {
            if (is_array($form) && ($form['status'] ?? null) === 'available' && !empty($form['url'])) {
                return [
                    'index' => (int) $index,
                    'url' => (string) $form['url'],
                ];
            }
        }

        return null;
    }

    public function markUsed(int $index, string $dedupeKey): void
    {
        // Called only after LINE WORKS send succeeds. Marking a form used before send
        // would lose the form when delivery fails.
        $this->load();

        if (!isset($this->data['forms'][$index]) || !is_array($this->data['forms'][$index])) {
            throw new RuntimeException('Selected form no longer exists.');
        }

        if (($this->data['forms'][$index]['status'] ?? null) !== 'available') {
            throw new RuntimeException('Selected form is not available.');
        }

        $this->data['forms'][$index]['status'] = 'used';
        $this->data['forms'][$index]['used_at'] = $this->now();
        $this->data['forms'][$index]['dedupe_key'] = $dedupeKey;
    }

    public function availableCount(): int
    {
        $data = $this->load();
        $count = 0;

        foreach ($data['forms'] as $form) {
            if (is_array($form) && ($form['status'] ?? null) === 'available') {
                $count++;
            }
        }

        return $count;
    }

    public function lowStockNotified(): bool
    {
        $data = $this->load();

        return (bool) ($data['low_stock_notified'] ?? false);
    }

    public function setLowStockNotified(bool $value): void
    {
        $this->load();
        $this->data['low_stock_notified'] = $value;
    }

    public function save(): void
    {
        // Save through a temporary file and rename. Directly overwriting forms.json
        // risks leaving a truncated file if the process is interrupted.
        $this->load();

        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new RuntimeException('Failed to create form stock directory.');
        }

        $json = json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if ($json === false) {
            throw new RuntimeException('Failed to encode form stock JSON: ' . json_last_error_msg());
        }

        $tmpPath = $this->path . '.' . getmypid() . '.tmp';

        if (file_put_contents($tmpPath, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write temporary form stock file.');
        }

        if (!rename($tmpPath, $this->path)) {
            @unlink($tmpPath);
            throw new RuntimeException('Failed to replace form stock file.');
        }
    }

    /** @return array{processed: bool, imported: int, duplicate_skipped: int, invalid_rows: int} */
    private function importCsv(): array
    {
        // CSV contract for operators:
        //   Row 1: URL
        //   Row 2+: one form URL per row
        // Empty rows are ignored. Existing URLs are counted as duplicates, not added.
        // This means re-uploading a used URL does not make it available again.
        $this->load();

        $handle = fopen($this->importCsvPath, 'r');
        if ($handle === false) {
            throw new FormImportException('Failed to open form import CSV.');
        }

        try {
            $header = fgetcsv($handle);
            if ($header === false || !isset($header[0]) || strtolower(trim((string) $header[0])) !== 'url') {
                throw new FormImportException('Form import CSV header must be URL.');
            }

            $knownUrls = $this->knownUrls();
            $imported = 0;
            $duplicateSkipped = 0;
            $invalidRows = 0;

            while (($row = fgetcsv($handle)) !== false) {
                $url = isset($row[0]) ? trim((string) $row[0]) : '';

                if ($url === '') {
                    continue;
                }

                if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                    // Count invalid rows but do not include the URL in logs/errors.
                    $invalidRows++;
                    continue;
                }

                if (isset($knownUrls[$url])) {
                    $duplicateSkipped++;
                    continue;
                }

                $this->data['forms'][] = [
                    'url' => $url,
                    'status' => 'available',
                    'imported_at' => $this->now(),
                    'used_at' => null,
                    'dedupe_key' => null,
                ];
                $knownUrls[$url] = true;
                $imported++;
            }
        } finally {
            fclose($handle);
        }

        return [
            'processed' => true,
            'imported' => $imported,
            'duplicate_skipped' => $duplicateSkipped,
            'invalid_rows' => $invalidRows,
        ];
    }

    /** @return array<string, bool> */
    private function knownUrls(): array
    {
        // Include both available and used URLs. This prevents accidental reuse when
        // an old CSV is uploaded again.
        $known = [];

        foreach ($this->data['forms'] as $form) {
            if (is_array($form) && isset($form['url'])) {
                $known[(string) $form['url']] = true;
            }
        }

        return $known;
    }

    private function moveImportFile(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
            throw new RuntimeException('Failed to create form import archive directory.');
        }

        $target = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . date('Ymd_His') . '_forms.csv';

        if (!rename($this->importCsvPath, $target)) {
            throw new RuntimeException('Failed to move form import CSV.');
        }
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        // Invalid JSON must stop processing. Treating a broken forms.json as empty
        // could make the bot skip notifications or recreate stock incorrectly.
        if ($this->data !== null) {
            return $this->data;
        }

        if (!is_file($this->path)) {
            $this->data = [
                'forms' => [],
                'low_stock_notified' => false,
            ];
            return $this->data;
        }

        $contents = file_get_contents($this->path);

        if ($contents === false) {
            throw new RuntimeException('Failed to read form stock file.');
        }

        $data = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Form stock JSON is invalid: ' . json_last_error_msg());
        }

        if (!is_array($data) || !isset($data['forms']) || !is_array($data['forms'])) {
            throw new RuntimeException('Form stock file schema is invalid.');
        }

        if (!array_key_exists('low_stock_notified', $data)) {
            $data['low_stock_notified'] = false;
        }

        $this->data = $data;

        return $this->data;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DateTimeInterface::ATOM);
    }
}
