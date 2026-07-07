<?php
declare(strict_types=1);

final class Config
{
    // 実運用の設定は Git 管理外の config.php に置く。
    // このクラスは値そのものを表示せず、必須項目・型・プレースホルダー残りを起動時に検出する。
    // config.example.php は公開できるテンプレート、config.php はGit管理外の実設定。
    // ここでは実値を表示せず、空値・型・本番/テスト切り替え条件だけを検証する。
    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function load(string $path): self
    {
        // 実 config.php は秘密値を含むため、このメソッドでも中身は出力しない。
        // エラーは「どの設定が足りないか」までに留め、値そのものは表示しない。
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
        // P2PQuakeの震度コードを返す。例: 45 は震度5弱相当、0 は疎通・検証用。
        // EarthquakeChecker はこの値未満の地震を通知対象外にする。
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

    public function formStockEnabled(): bool
    {
        // 未設定時は true 扱いにする。
        // PHP版の本番運用はフォームストック前提。固定form_urlへの暗黙fallbackは再利用事故につながるため行わない。
        // 既存の実 config.php には、フォームストック関連設定を追加してから利用すること。
        if (!array_key_exists('form_stock_enabled', $this->values)) {
            return true;
        }

        return (bool) filter_var($this->values['form_stock_enabled'], FILTER_VALIDATE_BOOLEAN);
    }

    public function privateKeyPath(): string
    {
        return (string) $this->values['private_key_path'];
    }

    public function formStockPath(): string
    {
        return (string) ($this->values['form_stock_path'] ?? dirname(__DIR__) . '/storage/forms.json');
    }

    public function formImportCsvPath(): string
    {
        return (string) ($this->values['form_import_csv_path'] ?? dirname(__DIR__) . '/forms/forms.csv');
    }

    public function formImportProcessedDir(): string
    {
        return (string) ($this->values['form_import_processed_dir'] ?? dirname(__DIR__) . '/forms/processed');
    }

    public function formImportFailedDir(): string
    {
        return (string) ($this->values['form_import_failed_dir'] ?? dirname(__DIR__) . '/forms/failed');
    }

    public function formLowStockThreshold(): int
    {
        return (int) ($this->values['form_low_stock_threshold'] ?? 10);
    }

    public function formLowStockRoomId(): string
    {
        return (string) ($this->values['form_low_stock_room_id'] ?? '');
    }

    public function unknownHypocenterHoldSeconds(): int
    {
        // UNKNOWN 震源地は即通知せず、震源地名ありの続報を待つ。
        // 未設定の既存 config.php でも従来運用を壊さないよう、デフォルトは600秒にする。
        return (int) ($this->values['unknown_hypocenter_hold_seconds'] ?? 600);
    }

    private function validate(): void
    {
        // ここで落とせる設定ミスは起動時に止める。
        // 送信途中で不足に気づくと、通知漏れやフォームURLだけ消費する事故に近づくため。
        // 起動時に設定不備を止める。
        // 送信途中で不足に気づくと、通知漏れやフォーム消費だけが起きる事故につながる。
        $this->requireNotifyScale();
        $this->requireIntegerIfPresent('unknown_hypocenter_hold_seconds');
        $this->requireBooleanIfPresent('form_stock_enabled');

        foreach ([
            'client_id',
            'client_secret',
            'service_account',
            'bot_id',
            'room_id',
            'private_key_path',
        ] as $key) {
            $this->requireNonEmptyString($key);
        }

        if ($this->formStockEnabled()) {
            // 本番推奨のフォームストック運用。
            // 在庫ファイル・CSV取り込み先・補充通知先がないと運用できないため必須にする。
            $this->requireNumeric('form_low_stock_threshold');

            foreach ([
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
        } else {
            // form_stock_enabled=false はテスト用の固定URLモード。
            // form_url が空、またはプレースホルダーのまま送ると利用者に無効な安否確認を出すため、起動時に止める。
            $this->requireNonEmptyString('form_url');
        }

        if ($this->unknownHypocenterHoldSeconds() <= 0) {
            throw new RuntimeException('Config value must be greater than 0: unknown_hypocenter_hold_seconds');
        }

        if (!is_readable($this->privateKeyPath())) {
            throw new RuntimeException('Private key file is not readable.');
        }
    }

    private function requireNonEmptyString(string $key): void
    {
        // 空文字と REPLACE_WITH_... はどちらも未設定扱いにする。
        // 値そのものは例外文へ出さず、どのキーが未設定かだけを伝える。
        if (!array_key_exists($key, $this->values) || trim((string) $this->values[$key]) === '') {
            throw new RuntimeException('Missing required config value: ' . $key);
        }

        if (str_starts_with(trim((string) $this->values[$key]), 'REPLACE_WITH_')) {
            throw new RuntimeException('Config placeholder must be replaced: ' . $key);
        }
    }

    private function requireNumeric(string $key): void
    {
        if (!array_key_exists($key, $this->values) || !is_numeric($this->values[$key])) {
            throw new RuntimeException('Config value must be numeric: ' . $key);
        }
    }

    private function requireNotifyScale(): void
    {
        // notify_scale はP2PQuakeの震度コードだけを許可する。
        // 任意の数値を通すと、実在しない閾値で通知漏れ/過剰通知になり得る。
        $this->requireNumeric('notify_scale');
        $this->requireIntegerIfPresent('notify_scale');

        $allowedScales = [0, 10, 20, 30, 40, 45, 50, 55, 60, 70];
        if (!in_array((int) $this->values['notify_scale'], $allowedScales, true)) {
            throw new RuntimeException('Config value is not an allowed seismic intensity code: notify_scale');
        }
    }

    private function requireIntegerIfPresent(string $key): void
    {
        if (!array_key_exists($key, $this->values)) {
            return;
        }

        $value = $this->values[$key];
        if (is_int($value)) {
            return;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return;
        }

        throw new RuntimeException('Config value must be integer: ' . $key);
    }

    private function requireBooleanIfPresent(string $key): void
    {
        // 文字列の true/false も許容するが、typo は見逃さない。
        // 例: fals のような値を false と誤解釈すると、本番で固定URL運用になる恐れがある。
        if (!array_key_exists($key, $this->values)) {
            return;
        }

        if (filter_var($this->values[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null) {
            throw new RuntimeException('Config value must be boolean: ' . $key);
        }
    }
}
