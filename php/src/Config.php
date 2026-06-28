<?php
declare(strict_types=1);

final class Config
{
    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values */
    private function __construct(array $values)
    {
        $this->values = $values;
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

        $config = new self($values);
        $config->validate();

        return $config;
    }

    public function notifyScale(): int
    {
        return (int) $this->values['notify_scale'];
    }

    public function p2pquakeApiUrl(): string
    {
        return (string) ($this->values['p2pquake_api_url'] ?? 'https://api.p2pquake.net/v2/jma/quake');
    }

    public function lineWorksTokenUrl(): string
    {
        return (string) ($this->values['lineworks_token_url'] ?? 'https://auth.worksmobile.com/oauth2/v2.0/token');
    }

    public function clientId(): string
    {
        return (string) $this->values['client_id'];
    }

    public function clientSecret(): string
    {
        return (string) $this->values['client_secret'];
    }

    public function serviceAccount(): string
    {
        return (string) $this->values['service_account'];
    }

    public function botId(): string
    {
        return (string) $this->values['bot_id'];
    }

    public function roomId(): string
    {
        return (string) $this->values['room_id'];
    }

    public function formUrl(): string
    {
        return (string) ($this->values['form_url'] ?? '');
    }

    public function privateKeyPath(): string
    {
        return (string) $this->values['private_key_path'];
    }

    public function formStockPath(): string
    {
        return (string) $this->values['form_stock_path'];
    }

    public function formImportCsvPath(): string
    {
        return (string) $this->values['form_import_csv_path'];
    }

    public function formImportProcessedDir(): string
    {
        return (string) $this->values['form_import_processed_dir'];
    }

    public function formImportFailedDir(): string
    {
        return (string) $this->values['form_import_failed_dir'];
    }

    public function formLowStockThreshold(): int
    {
        return (int) $this->values['form_low_stock_threshold'];
    }

    public function formLowStockRoomId(): string
    {
        return (string) $this->values['form_low_stock_room_id'];
    }

    private function validate(): void
    {
        $this->requireNumeric('notify_scale');
        $this->requireNumeric('form_low_stock_threshold');

        foreach ([
            'client_id',
            'client_secret',
            'service_account',
            'bot_id',
            'room_id',
            'private_key_path',
            'form_stock_path',
            'form_import_csv_path',
            'form_import_processed_dir',
            'form_import_failed_dir',
            'form_low_stock_room_id',
        ] as $key) {
            $this->requireNonEmptyString($key);
        }

        if ($this->formLowStockThreshold() < 1) {
            throw new RuntimeException('Config value must be greater than 0: form_low_stock_threshold');
        }

        if (!is_readable($this->privateKeyPath())) {
            throw new RuntimeException('Private key file is not readable: ' . $this->privateKeyPath());
        }
    }

    private function requireNonEmptyString(string $key): void
    {
        if (!array_key_exists($key, $this->values) || trim((string) $this->values[$key]) === '') {
            throw new RuntimeException('Missing required config value: ' . $key);
        }
    }

    private function requireNumeric(string $key): void
    {
        if (!array_key_exists($key, $this->values) || !is_numeric($this->values[$key])) {
            throw new RuntimeException('Config value must be numeric: ' . $key);
        }
    }
}
