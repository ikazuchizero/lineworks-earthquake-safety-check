<?php
declare(strict_types=1);

final class FormImportException extends RuntimeException
{
}

final class FormStockStore
{
    // forms.json は安否確認フォームURLの運用上の正本。
    // 各URLは最大1回だけ使う。実フォームURLは回答用リンクそのものなので、
    // 漏洩や誤回答を防ぐためログや標準出力へ出さない。
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
        // 非エンジニア担当者は forms/forms.csv をアップロードして補充する。
        // 成功したCSVは processed/ へ、失敗したCSVは failed/ へ移動し、
        // 問題のあるCSVを無限に再処理し続けないようにする。
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
        // 選べるのは available のフォームだけ。
        // used のフォームは、後から同じURLがCSVに入っても再利用しない。
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
        // LINE WORKS送信成功後にだけ呼ぶ。
        // 送信前にusedへ変えると、配信失敗時にフォームだけ失われる。
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
        // forms.json は一時ファイルに書いてからrenameで置き換える。
        // 直接上書きすると、処理中断時に壊れたJSONが残る可能性がある。
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
        // CSV運用ルール:
        //   1行目: URL
        //   2行目以降: フォームURLを1行に1件
        // 空行は無視する。既存URLは重複として数え、追加しない。
        // 使用済みURLを再アップロードしても available には戻さない。
        $this->load();

        $handle = fopen($this->importCsvPath, 'r');
        if ($handle === false) {
            throw new FormImportException('Failed to open form import CSV.');
        }

        try {
            $header = fgetcsv($handle);
            if ($header === false || !isset($header[0]) || strtolower(trim($this->removeUtf8Bom((string) $header[0]))) !== 'url') {
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
                    // 不正行は件数だけ数える。URL本文はログやエラーに含めない。
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

    private function removeUtf8Bom(string $value): string
    {
        // Excelなどで作成したCSVは、先頭ヘッダーにUTF-8 BOMが付くことがある。
        // BOM付きでも URL ヘッダーとして扱えるよう、URL本文は出力せずヘッダーだけ正規化する。
        return preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    }

    /** @return array<string, bool> */
    private function knownUrls(): array
    {
        // available と used の両方を既知URLとして扱う。
        // 古いCSVを再アップロードしても使用済みフォームを再利用しないため。
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
        // 取り込み後のCSVは削除せず processed/ または failed/ に残す。
        // 非エンジニア運用で「アップロードしたCSVがどうなったか」を追えるようにするため。
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
        // 不正JSONは処理停止にする。
        // 壊れた forms.json を空扱いすると、在庫状態を誤認して通知漏れや誤補充につながる。
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
        // forms.jsonの時刻は運用者が追いやすいようAsia/TokyoのISO8601で保存する。
        // 地震発生時刻の表示処理とは別で、フォーム在庫の操作時刻を記録するため。
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DateTimeInterface::ATOM);
    }
}
