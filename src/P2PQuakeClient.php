<?php
declare(strict_types=1);

final class P2PQuakeClient
{
    private string $apiUrl;
    private int $limit;

    public function __construct(string $apiUrl, int $limit = 10)
    {
        if ($limit < 2) {
            throw new InvalidArgumentException('P2PQuake limit must be greater than 1.');
        }

        $this->apiUrl = $apiUrl;
        $this->limit = $limit;
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchEarthquakes(): array
    {
        $url = $this->buildUrl();
        $headers = [];
        $body = $this->request('GET', $url, [], null, $headers);
        $statusCode = $this->statusCodeFromHeaders($headers);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException('P2PQuake API failed. HTTP:' . $statusCode . ' ' . $body);
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
