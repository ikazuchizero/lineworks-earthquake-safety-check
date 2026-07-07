<?php
declare(strict_types=1);

final class SetupChecker
{
    private string $rootDir;

    public function __construct(string $rootDir)
    {
        $this->rootDir = rtrim($rootDir, '/\\');
    }

    /** @return array<int, array{name: string, status: string, reason: string}> */
    public function run(): array
    {
        $results = [];
        $configPath = $this->rootDir . '/config.php';

        // 初回アップロード直後に、配置漏れ・権限不足・設定不足をまとめて確認するためのチェック。
        // 実設定値や秘密鍵の中身は表示せず、setup_check.php では OK/NG と原因分類だけを出す。
        $results[] = $this->checkFileReadable('config_file', $configPath);

        $config = null;
        try {
            $config = Config::load($configPath);
            $results[] = $this->ok('config_values');
        } catch (Throwable $e) {
            $results[] = $this->ng('config_values', $this->safeReason($e));
        }

        $storageDir = $this->rootDir . '/storage';
        $results[] = $this->checkDirectoryWritable('storage_dir', $storageDir);

        if ($config === null) {
            return $results;
        }

        // 秘密鍵は中身を検査せず、存在・読込可・空でないことだけを見る。
        // パスの詳細や秘密情報を出すと、設置確認ログ自体が漏洩リスクになるため。
        $results[] = $this->checkFileReadable('private_key_file', $config->privateKeyPath(), true);
        $results[] = $this->checkDirectoryWritable('state_dir', dirname($this->rootDir . '/storage/state.json'));

        if ($config->formStockEnabled()) {
            // フォームストック運用では、補充CSVの配置先・処理済み/失敗CSVの移動先・
            // forms.json の保存先が揃っていないと、補充失敗やフォーム再利用事故につながる。
            $results[] = $this->checkDirectoryWritable('forms_dir', $this->rootDir . '/forms');
            $results[] = $this->checkDirectoryWritable('form_processed_dir', $config->formImportProcessedDir());
            $results[] = $this->checkDirectoryWritable('form_failed_dir', $config->formImportFailedDir());
            $results[] = $this->checkDirectoryWritable('form_stock_dir', dirname($config->formStockPath()));
        }

        // form_stock_enabled=false は検証用の固定form_urlモード。
        // Config::load() が固定URLの妥当性を検証済みなので、forms系ディレクトリは必須扱いしない。
        return $results;
    }

    /** @param array<int, array{name: string, status: string, reason: string}> $results */
    public function isOk(array $results): bool
    {
        foreach ($results as $result) {
            if ($result['status'] !== 'OK') {
                return false;
            }
        }

        return true;
    }

    /** @return array{name: string, status: string, reason: string} */
    private function checkFileReadable(string $name, string $path, bool $requireNonEmpty = false): array
    {
        if (!is_file($path)) {
            return $this->ng($name, 'missing');
        }

        if (!is_readable($path)) {
            return $this->ng($name, 'not_readable');
        }

        if ($requireNonEmpty && filesize($path) === 0) {
            return $this->ng($name, 'empty');
        }

        return $this->ok($name);
    }

    /** @return array{name: string, status: string, reason: string} */
    private function checkDirectoryWritable(string $name, string $path): array
    {
        if (!is_dir($path)) {
            return $this->ng($name, 'missing');
        }

        if (!is_writable($path)) {
            return $this->ng($name, 'not_writable');
        }

        return $this->ok($name);
    }

    /** @return array{name: string, status: string, reason: string} */
    private function ok(string $name): array
    {
        return [
            'name' => $name,
            'status' => 'OK',
            'reason' => '',
        ];
    }

    /** @return array{name: string, status: string, reason: string} */
    private function ng(string $name, string $reason): array
    {
        return [
            'name' => $name,
            'status' => 'NG',
            'reason' => $reason,
        ];
    }

    private function safeReason(Throwable $e): string
    {
        $message = $e->getMessage();
        if (str_contains($message, ':')) {
            return trim((string) strstr($message, ':', true));
        }

        return $message !== '' ? $message : 'failed';
    }
}
