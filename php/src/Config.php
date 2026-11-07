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
    private string $googleOauthClientId;
    private string $googleOauthClientSecret;
    private string $googleOauthRefreshToken;
    private string $googleFormsTemplateId;
    private string $privateKeyPath;
    private int $unknownHypocenterHoldSeconds;
    private int $quietHoursStart;
    private int $quietHoursEnd;

    private function __construct(
        int $notifyScale,
        string $p2pquakeApiUrl,
        string $lineWorksTokenUrl,
        string $clientId,
        string $clientSecret,
        string $serviceAccount,
        string $botId,
        string $roomId,
        string $googleOauthClientId,
        string $googleOauthClientSecret,
        string $googleOauthRefreshToken,
        string $googleFormsTemplateId,
        string $privateKeyPath,
        int $unknownHypocenterHoldSeconds,
        int $quietHoursStart,
        int $quietHoursEnd
    ) {
        if (!in_array($notifyScale, [0, 10, 20, 30, 40, 45, 50, 55, 60, 70], true)) {
            throw new RuntimeException('Config value is not an allowed seismic intensity code: notify_scale');
        }
        if ($unknownHypocenterHoldSeconds <= 0) {
            throw new RuntimeException('Config value must be greater than 0: unknown_hypocenter_hold_seconds');
        }
        if ($quietHoursStart < 0 || $quietHoursStart > 23 || $quietHoursEnd < 0 || $quietHoursEnd > 23) {
            throw new RuntimeException('Config value must be between 0 and 23: quiet_hours');
        }
        if ($quietHoursStart === $quietHoursEnd) {
            throw new RuntimeException('Config quiet hours start and end must differ.');
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
        $this->googleOauthClientId = $googleOauthClientId;
        $this->googleOauthClientSecret = $googleOauthClientSecret;
        $this->googleOauthRefreshToken = $googleOauthRefreshToken;
        $this->googleFormsTemplateId = $googleFormsTemplateId;
        $this->privateKeyPath = $privateKeyPath;
        $this->unknownHypocenterHoldSeconds = $unknownHypocenterHoldSeconds;
        $this->quietHoursStart = $quietHoursStart;
        $this->quietHoursEnd = $quietHoursEnd;
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
        self::requireIntegerIfPresent($values, 'quiet_hours_start');
        self::requireIntegerIfPresent($values, 'quiet_hours_end');
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

        foreach (['google_oauth_client_id', 'google_oauth_client_secret', 'google_oauth_refresh_token', 'google_forms_template_id'] as $key) {
            self::requireNonEmptyString($values, $key);
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
            self::requiredStringValue($values, 'google_oauth_client_id'),
            self::requiredStringValue($values, 'google_oauth_client_secret'),
            self::requiredStringValue($values, 'google_oauth_refresh_token'),
            self::requiredStringValue($values, 'google_forms_template_id'),
            self::requiredStringValue($values, 'private_key_path'),
            self::integerValue($values, 'unknown_hypocenter_hold_seconds', 600),
            self::integerValue($values, 'quiet_hours_start', 0),
            self::integerValue($values, 'quiet_hours_end', 6)
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
        return $this->roomId;
    }

    public function googleOauthClientId(): string { return $this->googleOauthClientId; }
    public function googleOauthClientSecret(): string { return $this->googleOauthClientSecret; }
    public function googleOauthRefreshToken(): string { return $this->googleOauthRefreshToken; }
    public function googleFormsTemplateId(): string { return $this->googleFormsTemplateId; }

    public function privateKeyPath(): string
    {
        return $this->privateKeyPath;
    }

    public function unknownHypocenterHoldSeconds(): int
    {
        return $this->unknownHypocenterHoldSeconds;
    }

    public function quietHoursStart(): int
    {
        return $this->quietHoursStart;
    }

    public function quietHoursEnd(): int
    {
        return $this->quietHoursEnd;
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

}
