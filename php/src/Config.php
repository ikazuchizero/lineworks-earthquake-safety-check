<?php
declare(strict_types=1);

final class Config
{
    // 実運用の設定は Git 管理外の config.php に置く。
    // このクラスは値そのものを表示せず、必須項目・型・プレースホルダー残りを起動時に検出する。
    private int $notifyScale;
    private string $p2pquakeApiUrl;
    private string $lineWorksTokenUrl;
    private string $clientId;
    private string $clientSecret;
    private string $serviceAccount;
    private string $botId;
    private string $roomId;
    private bool $formStockEnabled;
    private string $formUrl;
    private string $privateKeyPath;
    private string $formStockPath;
    private string $formImportCsvPath;
    private string $formImportProcessedDir;
    private string $formImportFailedDir;
    private int $formLowStockThreshold;
    private string $formLowStockRoomId;
    private int $unknownHypocenterHoldSeconds;

    private function __construct(
        int $notifyScale,
        string $p2pquakeApiUrl,
        string $lineWorksTokenUrl,
        string $clientId,
        string $clientSecret,
        string $serviceAccount,
        string $botId,
        string $roomId,
        bool $formStockEnabled,
        string $formUrl,
        string $privateKeyPath,
        string $formStockPath,
        string $formImportCsvPath,
        string $formImportProcessedDir,
        string $formImportFailedDir,
        int $formLowStockThreshold,
        string $formLowStockRoomId,
        int $unknownHypocenterHoldSeconds
    ) {
        if (!in_array($notifyScale, [0, 10, 20, 30, 40, 45, 50, 55, 60, 70], true)) {
            throw new RuntimeException('Config value is not an allowed seismic intensity code: notify_scale');
        }
        if ($unknownHypocenterHoldSeconds <= 0) {
            throw new RuntimeException('Config value must be greater than 0: unknown_hypocenter_hold_seconds');
        }
        if ($formStockEnabled && $formLowStockThreshold <= 0) {
            throw new RuntimeException('Config value must be greater than 0: form_low_stock_threshold');
        }
        if (!is_readable($privateKeyPath)) {
            throw new RuntimeException('Private key file is not readable.');
        }

        $this->notifyScale = $notifyScale;
        $this->p2pquakeApiUrl = $p2pquakeApiUrl;
        $this->lineWorksTokenUrl = $lineWorksTokenUrl;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->serviceAccount = $serviceAccount;
        $this->botId = $botId;
        $this->roomId = $roomId;
        $this->formStockEnabled = $formStockEnabled;
        $this->formUrl = $formUrl;
        $this->privateKeyPath = $privateKeyPath;
        $this->formStockPath = $formStockPath;
        $this->formImportCsvPath = $formImportCsvPath;
        $this->formImportProcessedDir = $formImportProcessedDir;
        $this->formImportFailedDir = $formImportFailedDir;
        $this->formLowStockThreshold = $formLowStockThreshold;
        $this->formLowStockRoomId = $formLowStockRoomId;
        $this->unknownHypocenterHoldSeconds = $unknownHypocenterHoldSeconds;
    }

    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException('Config file not found: ' . $path);
        }

        $values = require $path;
        if (!is_array($values)) {
            throw new RuntimeException('Config file must return an array.');
        }

        self::requireNumeric($values, 'notify_scale');
        self::requireIntegerIfPresent($values, 'notify_scale');
        self::requireIntegerIfPresent($values, 'unknown_hypocenter_hold_seconds');
        self::requireBooleanIfPresent($values, 'form_stock_enabled');

        $formStockEnabled = self::booleanValue($values, 'form_stock_enabled', true);

        foreach ([
            'client_id',
            'client_secret',
            'service_account',
            'bot_id',
            'room_id',
            'private_key_path',
        ] as $key) {
            self::requireNonEmptyString($values, $key);
        }

        if ($formStockEnabled) {
            self::requireNumeric($values, 'form_low_stock_threshold');
            foreach ([
                'form_stock_path',
                'form_import_csv_path',
                'form_import_processed_dir',
                'form_import_failed_dir',
                'form_low_stock_room_id',
            ] as $key) {
                self::requireNonEmptyString($values, $key);
            }
        } else {
            self::requireNonEmptyString($values, 'form_url');
        }

        return new self(
            self::integerValue($values, 'notify_scale'),
            self::stringValue($values, 'p2pquake_api_url', 'https://api.p2pquake.net/v2/jma/quake'),
            self::stringValue($values, 'lineworks_token_url', 'https://auth.worksmobile.com/oauth2/v2.0/token'),
            self::requiredStringValue($values, 'client_id'),
            self::requiredStringValue($values, 'client_secret'),
            self::requiredStringValue($values, 'service_account'),
            self::requiredStringValue($values, 'bot_id'),
            self::requiredStringValue($values, 'room_id'),
            $formStockEnabled,
            $formStockEnabled ? '' : self::requiredStringValue($values, 'form_url'),
            self::requiredStringValue($values, 'private_key_path'),
            self::stringValue($values, 'form_stock_path', dirname(__DIR__) . '/storage/forms.json'),
            self::stringValue($values, 'form_import_csv_path', dirname(__DIR__) . '/forms/forms.csv'),
            self::stringValue($values, 'form_import_processed_dir', dirname(__DIR__) . '/forms/processed'),
            self::stringValue($values, 'form_import_failed_dir', dirname(__DIR__) . '/forms/failed'),
            $formStockEnabled ? self::integerValue($values, 'form_low_stock_threshold', 10) : 10,
            $formStockEnabled ? self::requiredStringValue($values, 'form_low_stock_room_id') : '',
            self::integerValue($values, 'unknown_hypocenter_hold_seconds', 600)
        );
    }

    public function notifyScale(): int
    {
        return $this->notifyScale;
    }

    public function p2pquakeApiUrl(): string
    {
        return $this->p2pquakeApiUrl;
    }

    public function lineWorksTokenUrl(): string
    {
        return $this->lineWorksTokenUrl;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function clientSecret(): string
    {
        return $this->clientSecret;
    }

    public function serviceAccount(): string
    {
        return $this->serviceAccount;
    }

    public function botId(): string
    {
        return $this->botId;
    }

    public function roomId(): string
    {
        return $this->roomId;
    }

    public function maintenanceRoomId(): string
    {
        return $this->formStockEnabled ? $this->formLowStockRoomId : $this->roomId;
    }

    public function formUrl(): string
    {
        return $this->formUrl;
    }

    public function formStockEnabled(): bool
    {
        return $this->formStockEnabled;
    }

    public function privateKeyPath(): string
    {
        return $this->privateKeyPath;
    }

    public function formStockPath(): string
    {
        return $this->formStockPath;
    }

    public function formImportCsvPath(): string
    {
        return $this->formImportCsvPath;
    }

    public function formImportProcessedDir(): string
    {
        return $this->formImportProcessedDir;
    }

    public function formImportFailedDir(): string
    {
        return $this->formImportFailedDir;
    }

    public function formLowStockThreshold(): int
    {
        return $this->formLowStockThreshold;
    }

    public function formLowStockRoomId(): string
    {
        return $this->formLowStockRoomId;
    }

    public function unknownHypocenterHoldSeconds(): int
    {
        return $this->unknownHypocenterHoldSeconds;
    }

    /** @param array<string, mixed> $values */
    private static function requiredStringValue(array $values, string $key): string
    {
        self::requireNonEmptyString($values, $key);

        return trim((string) $values[$key]);
    }

    /** @param array<string, mixed> $values */
    private static function stringValue(array $values, string $key, string $default): string
    {
        if (!array_key_exists($key, $values)) {
            return $default;
        }

        return trim((string) $values[$key]);
    }

    /** @param array<string, mixed> $values */
    private static function integerValue(array $values, string $key, int $default = 0): int
    {
        if (!array_key_exists($key, $values)) {
            return $default;
        }

        return (int) $values[$key];
    }

    /** @param array<string, mixed> $values */
    private static function booleanValue(array $values, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $values)) {
            return $default;
        }

        return (bool) filter_var($values[$key], FILTER_VALIDATE_BOOLEAN);
    }

    /** @param array<string, mixed> $values */
    private static function requireNonEmptyString(array $values, string $key): void
    {
        if (!array_key_exists($key, $values) || trim((string) $values[$key]) === '') {
            throw new RuntimeException('Missing required config value: ' . $key);
        }
        if (str_starts_with(trim((string) $values[$key]), 'REPLACE_WITH_')) {
            throw new RuntimeException('Config placeholder must be replaced: ' . $key);
        }
    }

    /** @param array<string, mixed> $values */
    private static function requireNumeric(array $values, string $key): void
    {
        if (!array_key_exists($key, $values) || !is_numeric($values[$key])) {
            throw new RuntimeException('Config value must be numeric: ' . $key);
        }
    }

    /** @param array<string, mixed> $values */
    private static function requireIntegerIfPresent(array $values, string $key): void
    {
        if (!array_key_exists($key, $values)) {
            return;
        }

        $value = $values[$key];
        if (is_int($value)) {
            return;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return;
        }

        throw new RuntimeException('Config value must be integer: ' . $key);
    }

    /** @param array<string, mixed> $values */
    private static function requireBooleanIfPresent(array $values, string $key): void
    {
        if (!array_key_exists($key, $values)) {
            return;
        }
        if (filter_var($values[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null) {
            throw new RuntimeException('Config value must be boolean: ' . $key);
        }
    }
}
