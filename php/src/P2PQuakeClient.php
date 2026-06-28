<?php
declare(strict_types=1);

final class P2PQuakeClient
{
    // P2PQuake APIから地震情報を取得するだけのクラス。
    // 通知判定や重複判定はEarthquakeChecker側で行い、ここではレスポンスを大きく加工しない。
    private string $apiUrl;
    private int $limit;

    public function __construct(string $apiUrl, int $limit = 10)
    {
        // limit=1は禁止。対象地震の直後に対象外情報が最新になると通知漏れするため、
        // 呼び出し側では複数件、現在は10件を見る前提にしている。
        if ($limit < 2) {
            throw new InvalidArgumentException('P2PQuake limit must be greater than 1.');
        }

        $this->apiUrl = $apiUrl;
        $this->limit = $limit;
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchEarthquakes(): array
    {
        // 外部APIなので、HTTP失敗・JSON不正・想定外の形は例外で止める。
        // 不完全なデータを空扱いすると通知漏れの原因になる。
        $url = $this->buildUrl();
        $headers = [];
        $body = $this->request('GET', $url, [], null, $headers);
        $statusCode = $this->statusCodeFromHeaders($headers);

        if ($statusCode < 200 || $statusCode >= 300) {
            // P2PQuakeにはcredentialを送らないが、外部APIレスポンスbodyをログへ残す必要はない。
            // 調査に必要なHTTP statusだけ残す。
            throw new RuntimeException('P2PQuake API request failed. HTTP status: ' . $statusCode);
        }

        $json = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('P2PQuake JSON parse failed: ' . json_last_error_msg());
        }

        if (!is_array($json)) {
            throw new RuntimeException('P2PQuake response must be an array.');
        }

        return $json;
    }

    private function buildUrl(): string
    {
        // 既存クエリがあっても limit を明示的に上書きする。
        // 運用上の通知漏れ防止条件をURL設定側のミスで崩さないため。
        $parts = parse_url($this->apiUrl);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('Invalid P2PQuake API URL: ' . $this->apiUrl);
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $query['limit'] = (string) $this->limit;

        $url = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $url .= ':' . $parts['port'];
        }
        $url .= $parts['path'] ?? '';
        $url .= '?' . http_build_query($query);

        return $url;
    }

    /**
     * @param array<string, string> $requestHeaders
     * @param array<int, string> $responseHeaders
     */
    private function request(string $method, string $url, array $requestHeaders, ?string $body, array &$responseHeaders): string
    {
        // 外部API通信はタイムアウト付きにする。
        // cronが詰まり続けると次回実行やlockに影響するため。
        $options = [
            'http' => [
                'method' => $method,
                'header' => $this->formatHeaders($requestHeaders),
                'content' => $body ?? '',
                'ignore_errors' => true,
                'timeout' => 20,
            ],
        ];

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header ?? [];

        if ($response === false) {
            throw new RuntimeException('P2PQuake HTTP request failed.');
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
        // HTTP/1.1 200 OK のようなステータス行から3桁コードを取り出す。
        // 取得失敗は例外にして、古いstateやフォーム状態を不用意に更新しない。
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches)) {
                return (int) $matches[1];
            }
        }

        return 0;
    }
}
