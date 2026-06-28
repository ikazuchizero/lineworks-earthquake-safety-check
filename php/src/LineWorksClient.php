<?php
declare(strict_types=1);

final class LineWorksClient
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function sendMessage(string $text): void
    {
        $accessToken = $this->getAccessToken();
        $botId = rawurlencode($this->config->botId());
        $roomId = rawurlencode($this->config->roomId());
        $url = 'https://www.worksapis.com/v1.0/bots/' . $botId . '/channels/' . $roomId . '/messages';

        $payload = json_encode([
            'content' => [
                'type' => 'text',
                'text' => $text,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new RuntimeException('Failed to encode LINE WORKS message JSON.');
        }

        $headers = [];
        $body = $this->request('POST', $url, [
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
        ], $payload, $headers);
        $statusCode = $this->statusCodeFromHeaders($headers);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException('LINE WORKS send failed. HTTP:' . $statusCode . ' ' . $body);
        }
    }

    private function getAccessToken(): string
    {
        $jwt = $this->createJwt();

        $payload = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
            'client_id' => $this->config->clientId(),
            'client_secret' => $this->config->clientSecret(),
            'scope' => 'bot.message',
        ]);

        $headers = [];
        $body = $this->request('POST', $this->config->lineWorksTokenUrl(), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], $payload, $headers);
        $statusCode = $this->statusCodeFromHeaders($headers);

        $json = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('LINE WORKS token JSON parse failed: ' . json_last_error_msg());
        }

        if ($statusCode !== 200 || !is_array($json) || empty($json['access_token'])) {
            throw new RuntimeException('LINE WORKS token failed. HTTP:' . $statusCode . ' ' . $body);
        }

        return (string) $json['access_token'];
    }

    private function createJwt(): string
    {
        if (!function_exists('openssl_sign')) {
            throw new RuntimeException('OpenSSL extension is required to sign LINE WORKS JWT.');
        }

        $privateKey = file_get_contents($this->config->privateKeyPath());

        if ($privateKey === false || trim($privateKey) === '') {
            throw new RuntimeException('Private key file is empty or unreadable.');
        }

        $now = time();
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];
        $payload = [
            'iss' => $this->config->clientId(),
            'sub' => $this->config->serviceAccount(),
            'iat' => $now,
            'exp' => $now + 3600,
            'aud' => $this->config->lineWorksTokenUrl(),
        ];

        $encodedHeader = $this->base64UrlEncode($this->jsonEncode($header));
        $encodedPayload = $this->base64UrlEncode($this->jsonEncode($payload));
        $signatureInput = $encodedHeader . '.' . $encodedPayload;

        $signature = '';
        if (!openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Failed to sign LINE WORKS JWT.');
        }

        return $signatureInput . '.' . $this->base64UrlEncode($signature);
    }

    /** @param array<string, mixed> $data */
    private function jsonEncode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException('Failed to encode JWT JSON.');
        }

        return $json;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @param array<string, string> $requestHeaders
     * @param array<int, string> $responseHeaders
     */
    private function request(string $method, string $url, array $requestHeaders, string $body, array &$responseHeaders): string
    {
        $options = [
            'http' => [
                'method' => $method,
                'header' => $this->formatHeaders($requestHeaders),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 20,
            ],
        ];

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header ?? [];

        if ($response === false) {
            $error = error_get_last();
            throw new RuntimeException('HTTP request failed: ' . ($error['message'] ?? $url));
        }

        return $response;
    }

    /** @param array<string, string> $headers */
    private function formatHeaders(array $headers): string
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return implode("\r\n", $lines);
    }

    /** @param array<int, string> $headers */
    private function statusCodeFromHeaders(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches)) {
                return (int) $matches[1];
            }
        }

        return 0;
    }
}
