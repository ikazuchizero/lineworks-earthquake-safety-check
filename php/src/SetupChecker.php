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

        $results[] = $this->checkFileReadable('config_file', $configPath);

        $config = null;
        try {
            $config = Config::load($configPath);
            $results[] = $this->ok('config_values');
        } catch (Throwable $e) {
            $results[] = $this->ng('config_values', $this->safeReason($e));
        }

        $storageDir = $this->rootDir . '/storage';
        $formsDir = $this->rootDir . '/forms';
        $results[] = $this->checkDirectoryWritable('storage_dir', $storageDir);
        $results[] = $this->checkDirectoryWritable('forms_dir', $formsDir);

        if ($config === null) {
            return $results;
        }

        $results[] = $this->checkFileReadable('private_key_file', $config->privateKeyPath(), true);
        $results[] = $this->checkDirectoryWritable('form_processed_dir', $config->formImportProcessedDir());
        $results[] = $this->checkDirectoryWritable('form_failed_dir', $config->formImportFailedDir());
        $results[] = $this->checkDirectoryWritable('state_dir', dirname($this->rootDir . '/storage/state.json'));
        $results[] = $this->checkDirectoryWritable('form_stock_dir', dirname($config->formStockPath()));

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
