<?php
declare(strict_types=1);

final class GoogleFormsClient
{
    private Config $config;
    private $transport;
    private ?string $accessToken = null;

    /** @param callable(string, string, array<string, string>, string): array{status:int,body:string} $transport */
    public function __construct(Config $config, ?callable $transport = null)
    {
        $this->config = $config;
        $this->transport = $transport ?? [$this, 'defaultTransport'];
    }

    public function assertTemplateCanPublish(): void
    {
        $form = $this->getForm($this->config->googleFormsTemplateId());
        if (!array_key_exists('publishSettings', $form)) throw new RuntimeException('Google Forms template does not support current publishing API.');
        if (($form['settings']['emailCollectionType'] ?? '') === 'VERIFIED') throw new RuntimeException('Google Forms template requires Google sign-in through verified email collection.');
    }

    public function copyTemplate(string $earthquakeTime): string
    {
        $label = 'Safety check ' . substr(preg_replace('/[^0-9]/', '', $earthquakeTime) ?? '', 0, 14);
        $json = $this->decodeSuccess($this->request('POST', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($this->config->googleFormsTemplateId()) . '/copy?fields=id', ['Content-Type' => 'application/json'], json_encode(['name' => $label]) ?: '{}'), 'copy');
        if (!is_string($json['id'] ?? null) || $json['id'] === '') throw new RuntimeException('Google Forms copy response is invalid.');
        return $json['id'];
    }

    public function prepareResponderUrl(string $formId): string
    {
        $form = $this->getForm($formId);
        if (!array_key_exists('publishSettings', $form)) throw new RuntimeException('Copied Google Form does not support current publishing API.');
        $state = $form['publishSettings']['publishState'] ?? [];
        if (($state['isPublished'] ?? false) !== true || ($state['isAcceptingResponses'] ?? false) !== true) {
            $body = json_encode(['publishSettings' => ['publishState' => ['isPublished' => true, 'isAcceptingResponses' => true]]]) ?: '{}';
            $this->decodeSuccess($this->request('POST', 'https://forms.googleapis.com/v1/forms/' . rawurlencode($formId) . ':setPublishSettings', ['Content-Type' => 'application/json'], $body), 'publish');
            $form = $this->getForm($formId);
        }
        $state = $form['publishSettings']['publishState'] ?? [];
        if (($state['isPublished'] ?? false) !== true || ($state['isAcceptingResponses'] ?? false) !== true) {
            throw new RuntimeException('Copied Google Form is not published and accepting responses.');
        }
        $this->ensurePublishedAnyoneReader($formId);
        $url = $form['responderUri'] ?? null;
        if (!is_string($url) || $url === '') throw new RuntimeException('Google Forms responder URL is unavailable.');
        return $url;
    }

    /** @return array<string, mixed> */
    private function getForm(string $formId): array
    {
        return $this->decodeSuccess($this->request('GET', 'https://forms.googleapis.com/v1/forms/' . rawurlencode($formId) . '?fields=formId,publishSettings,settings.emailCollectionType,responderUri', [], ''), 'form lookup');
    }

    private function ensurePublishedAnyoneReader(string $formId): void
    {
        $json = $this->decodeSuccess($this->request('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($formId) . '/permissions?includePermissionsForView=published&fields=permissions(id,type,role,view,allowFileDiscovery)', [], ''), 'permission lookup');
        foreach (is_array($json['permissions'] ?? null) ? $json['permissions'] : [] as $permission) {
            if (!is_array($permission) || ($permission['type'] ?? '') !== 'anyone' || ($permission['role'] ?? '') !== 'reader' || ($permission['view'] ?? '') !== 'published') continue;
            if (($permission['allowFileDiscovery'] ?? null) === false) return;
            $permissionId = $permission['id'] ?? null;
            if (!is_string($permissionId) || $permissionId === '') throw new RuntimeException('Google Forms published permission ID is unavailable.');
            $this->decodeSuccess($this->request('PATCH', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($formId) . '/permissions/' . rawurlencode($permissionId), ['Content-Type' => 'application/json'], '{"allowFileDiscovery":false}'), 'permission update');
            return;
        }
        $body = json_encode(['type' => 'anyone', 'role' => 'reader', 'view' => 'published', 'allowFileDiscovery' => false]) ?: '{}';
        $this->decodeSuccess($this->request('POST', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($formId) . '/permissions', ['Content-Type' => 'application/json'], $body), 'permission create');
    }

    /** @return array<string, mixed> */
    private function decodeSuccess(array $response, string $phase): array
    {
        if ($response['status'] < 200 || $response['status'] >= 300) throw new RuntimeException('Google Forms ' . $phase . ' failed. HTTP status: ' . $response['status']);
        $json = json_decode($response['body'], true);
        if (!is_array($json)) throw new RuntimeException('Google Forms ' . $phase . ' response is invalid.');
        return $json;
    }

    /** @return array{status:int,body:string} */
    private function request(string $method, string $url, array $headers, string $body): array
    {
        $headers['Authorization'] = 'Bearer ' . $this->token();
        return ($this->transport)($method, $url, $headers, $body);
    }

    private function token(): string
    {
        if ($this->accessToken !== null) return $this->accessToken;
        $response = ($this->transport)('POST', 'https://oauth2.googleapis.com/token', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['client_id' => $this->config->googleOauthClientId(), 'client_secret' => $this->config->googleOauthClientSecret(), 'refresh_token' => $this->config->googleOauthRefreshToken(), 'grant_type' => 'refresh_token']));
        if ($response['status'] !== 200) throw new RuntimeException('Google OAuth token refresh failed. HTTP status: ' . $response['status']);
        $json = json_decode($response['body'], true);
        if (!is_array($json) || !is_string($json['access_token'] ?? null) || $json['access_token'] === '') throw new RuntimeException('Google OAuth token response is invalid.');
        return $this->accessToken = $json['access_token'];
    }

    /** @return array{status:int,body:string} */
    private function defaultTransport(string $method, string $url, array $headers, string $body): array
    {
        $lines = []; foreach ($headers as $name => $value) $lines[] = $name . ': ' . $value;
        $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $lines), 'content' => $body, 'ignore_errors' => true, 'timeout' => 20]]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) throw new RuntimeException('Google Forms HTTP request failed.');
        $status = 0; foreach (($http_response_header ?? []) as $header) if (preg_match('#^HTTP/\\S+\\s+(\\d{3})#', $header, $m)) $status = (int) $m[1];
        return ['status' => $status, 'body' => $response];
    }
}
