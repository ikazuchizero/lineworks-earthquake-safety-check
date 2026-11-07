<?php
declare(strict_types=1);

final class QueuedGoogleTransport
{
    /** @var array<int, array{status:int,body:string}> */
    private array $responses;
    /** @var array<int, array{method:string,url:string,headers:array<string,string>,body:string}> */
    public array $requests = [];

    /** @param array<int, array{status:int,body:string}> $responses */
    public function __construct(array $responses) { $this->responses = $responses; }
    /** @return array{status:int,body:string} */
    public function __invoke(string $method, string $url, array $headers, string $body): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        if ($this->responses === []) throw new RuntimeException('unexpected Google request');
        return array_shift($this->responses);
    }
}

function googleConfig(string $dir): Config
{
    return Config::load(writeConfig($dir, [
        'google_oauth_client_id' => 'test-google-client',
        'google_oauth_client_secret' => 'test-google-secret',
        'google_oauth_refresh_token' => 'test-google-refresh',
        'google_forms_template_id' => 'template-id',
    ]));
}

/** @return array{status:int,body:string} */
function googleResponse(array $value, int $status = 200): array { return ['status' => $status, 'body' => json_encode($value) ?: '{}']; }

register_test('Google Forms auto mode copies publishes grants published responder access and caches before LINE WORKS', static function (): void {
    $dir = tempDir(); $line = new FakeLineWorksClient(); $line->failMain = true;
    $transport = new QueuedGoogleTransport([
        googleResponse(['access_token' => 'synthetic-token']),
        googleResponse(['formId' => 'template-id', 'publishSettings' => []]),
        googleResponse(['id' => 'copy-id']),
        googleResponse(['formId' => 'copy-id', 'publishSettings' => ['publishState' => ['isPublished' => false, 'isAcceptingResponses' => false]]]),
        googleResponse(['formId' => 'copy-id', 'publishSettings' => ['publishState' => ['isPublished' => true, 'isAcceptingResponses' => true]]]),
        googleResponse(['formId' => 'copy-id', 'publishSettings' => ['publishState' => ['isPublished' => true, 'isAcceptingResponses' => true]], 'responderUri' => 'https://respond.invalid/only']),
        googleResponse(['permissions' => []]), googleResponse(['id' => 'permission-id']),
    ]);
    $config = googleConfig($dir); $client = new GoogleFormsClient($config, $transport);
    $failure = '';
    try { new EarthquakeChecker($config, new FakeP2PQuakeClient([namedEarthquakeEvent()]), $line, new StateStore($dir . '/state.json'), new Logger($dir . '/app.log'), $client)->run(); } catch (RuntimeException $e) { $failure = $e->getMessage(); }
    assertTrue(count($line->attempts) === 1 && str_contains($line->attempts[0]['text'], 'https://respond.invalid/only'), 'only responder URI must be sent to LINE WORKS: ' . $failure);
    $state = readState($dir); assertTrue(($state['google_forms_by_earthquake_time']['2026-07-05T12:00:00']['form_id'] ?? '') === 'copy-id', 'copied ID must be saved before send.');
    assertTrue(($state['google_forms_by_earthquake_time']['2026-07-05T12:00:00']['responder_url'] ?? '') === 'https://respond.invalid/only', 'ready URL must be saved before send.');
    $permission = end($transport->requests); assertTrue($permission['body'] === '{"type":"anyone","role":"reader","view":"published","allowFileDiscovery":false}', 'permission must be published-view anyone reader only.');
    foreach ($transport->requests as $request) assertTrue(!str_contains($request['url'], 'sendNotificationEmail'), 'anyone permission must not request notification email.');
    $copyCount = count(array_filter($transport->requests, static fn(array $r): bool => str_contains($r['url'], '/copy?')));
    assertTrue($copyCount === 1, 'one Drive copy is required.');

    $line->failMain = false;
    $retryTransport = new QueuedGoogleTransport([]);
    $retryClient = new GoogleFormsClient($config, $retryTransport);
    new EarthquakeChecker($config, new FakeP2PQuakeClient([namedEarthquakeEvent()]), $line, new StateStore($dir . '/state.json'), new Logger($dir . '/app.log'), $retryClient)->run();
    assertTrue(mainMessageCount($line) === 1, 'retry must deliver cached responder URL.');
    assertTrue($retryTransport->requests === [], 'retry with cached URL must make no Google calls or extra copy.');
});

register_test('Google Forms failures are safe and legacy templates do not call publish API', static function (): void {
    $dir = tempDir(); $line = new FakeLineWorksClient(); $config = googleConfig($dir);
    $transport = new QueuedGoogleTransport([googleResponse(['access_token' => 'synthetic-token']), googleResponse(['formId' => 'template-id'])]);
    try { new EarthquakeChecker($config, new FakeP2PQuakeClient([namedEarthquakeEvent()]), $line, new StateStore($dir . '/state.json'), new Logger($dir . '/app.log'), new GoogleFormsClient($config, $transport))->run(); } catch (RuntimeException) {}
    assertTrue($line->attempts === [], 'template failure must send no safety URL.');
    assertTrue(!is_file($dir . '/state.json') || readState($dir)['notified'] === [], 'template failure must not mark notified.');
    foreach ($transport->requests as $request) assertTrue(!str_contains($request['url'], 'setPublishSettings'), 'legacy template must not call unsupported publish API.');

    $bad = new QueuedGoogleTransport([['status' => 400, 'body' => 'SENTINEL_SECRET_BODY']]);
    try { (new GoogleFormsClient($config, $bad))->assertTemplateCanPublish(); } catch (RuntimeException $e) { assertTrue(!str_contains($e->getMessage(), 'SENTINEL_SECRET_BODY'), 'Google error bodies must not leak.'); }
});

register_test('Google Forms preparation retry reuses an ID saved before a later failure', static function (): void {
    $dir = tempDir(); $line = new FakeLineWorksClient(); $config = googleConfig($dir);
    $first = new QueuedGoogleTransport([
        googleResponse(['access_token' => 'synthetic-token']), googleResponse(['formId' => 'template-id', 'publishSettings' => []]),
        googleResponse(['id' => 'saved-copy-id']), ['status' => 500, 'body' => 'SENTINEL_SECRET_BODY'],
    ]);
    try { new EarthquakeChecker($config, new FakeP2PQuakeClient([namedEarthquakeEvent()]), $line, new StateStore($dir . '/state.json'), new Logger($dir . '/app.log'), new GoogleFormsClient($config, $first))->run(); } catch (RuntimeException) {}
    assertTrue((readState($dir)['google_forms_by_earthquake_time']['2026-07-05T12:00:00']['form_id'] ?? '') === 'saved-copy-id', 'ID must survive preparation failure.');
    $second = new QueuedGoogleTransport([
        googleResponse(['access_token' => 'synthetic-token']),
        googleResponse(['formId' => 'saved-copy-id', 'publishSettings' => ['publishState' => ['isPublished' => true, 'isAcceptingResponses' => true]], 'responderUri' => 'https://respond.invalid/retry']),
        googleResponse(['permissions' => [['id' => 'published-acl', 'type' => 'anyone', 'role' => 'reader', 'view' => 'published', 'allowFileDiscovery' => false]]]),
    ]);
    new EarthquakeChecker($config, new FakeP2PQuakeClient([namedEarthquakeEvent()]), $line, new StateStore($dir . '/state.json'), new Logger($dir . '/app.log'), new GoogleFormsClient($config, $second))->run();
    assertTrue(mainMessageCount($line) === 1 && str_contains($line->messages[0]['text'], 'https://respond.invalid/retry'), 'retry must send prepared responder URL.');
    foreach ($second->requests as $request) assertTrue(!str_contains($request['url'], '/copy?'), 'retry after saved ID must not copy another form.');
});

register_test('Google Forms existing published permissions are link-only before responder delivery', static function (): void {
    foreach ([true, false, null] as $discovery) {
        $dir = tempDir(); $line = new FakeLineWorksClient(); $config = googleConfig($dir);
        $permission = ['id' => 'published-acl', 'type' => 'anyone', 'role' => 'reader', 'view' => 'published'];
        if ($discovery !== null) $permission['allowFileDiscovery'] = $discovery;
        $responses = [
            googleResponse(['access_token' => 'synthetic-token']),
            googleResponse(['formId' => 'template-id', 'publishSettings' => []]),
            googleResponse(['id' => 'linked-copy']),
            googleResponse(['formId' => 'linked-copy', 'publishSettings' => ['publishState' => ['isPublished' => true, 'isAcceptingResponses' => true]], 'responderUri' => 'https://respond.invalid/link-only']),
            googleResponse(['permissions' => [$permission]]),
        ];
        if ($discovery !== false) $responses[] = googleResponse(['id' => 'published-acl']);
        $transport = new QueuedGoogleTransport($responses);
        $http = static function (string $method, string $url, array $headers, string $body) use ($transport, $line): array {
            if ($method === 'PATCH') assertTrue($line->attempts === [], 'Published permission must be updated before any LINE send.');
            return $transport($method, $url, $headers, $body);
        };
        new EarthquakeChecker($config, new FakeP2PQuakeClient([namedEarthquakeEvent()]), $line, new StateStore($dir . '/state.json'), new Logger($dir . '/app.log'), new GoogleFormsClient($config, $http))->run();
        $updates = array_values(array_filter($transport->requests, static fn(array $r): bool => $r['method'] === 'PATCH'));
        assertTrue(count($updates) === ($discovery === false ? 0 : 1), 'Discoverable or unknown published permission must be patched; explicit false must skip.');
        if ($updates !== []) {
            assertTrue($updates[0]['url'] === 'https://www.googleapis.com/drive/v3/files/linked-copy/permissions/published-acl', 'Update must address the same published permission ID.');
            assertTrue($updates[0]['body'] === '{"allowFileDiscovery":false}', 'Partial update must preserve view, type, and role.');
        }
        foreach ($transport->requests as $request) {
            assertTrue(!str_contains($request['url'], 'sendNotificationEmail'), 'Published anyone permission must not request notification email.');
            assertTrue(!($request['method'] === 'POST' && str_ends_with($request['url'], '/permissions')), 'Existing published permission must not create a duplicate or general ACL.');
            if (str_contains($request['url'], '/permissions?')) {
                assertTrue(str_contains($request['url'], 'includePermissionsForView=published') && str_contains($request['url'], 'fields=permissions(id,type,role,view,allowFileDiscovery)'), 'List must include published view, permission ID, and discovery flag.');
            }
        }
        assertTrue(mainMessageCount($line) === 1 && str_contains($line->messages[0]['text'], 'https://respond.invalid/link-only'), 'Prepared responder URI must reach LINE WORKS.');
        assertTrue((readState($dir)['google_forms_by_earthquake_time']['2026-07-05T12:00:00']['responder_url'] ?? '') === 'https://respond.invalid/link-only', 'Prepared URL must be persisted.');
    }
});

register_test('Google Forms published permission update failure retries the saved copy without sending early', static function (): void {
    $dir = tempDir(); $line = new FakeLineWorksClient(); $config = googleConfig($dir);
    $form = ['formId' => 'acl-retry-copy', 'publishSettings' => ['publishState' => ['isPublished' => true, 'isAcceptingResponses' => true]], 'responderUri' => 'https://respond.invalid/acl-retry'];
    $permission = ['id' => 'published-acl', 'type' => 'anyone', 'role' => 'reader', 'view' => 'published', 'allowFileDiscovery' => true];
    $first = new QueuedGoogleTransport([
        googleResponse(['access_token' => 'synthetic-token']), googleResponse(['formId' => 'template-id', 'publishSettings' => []]),
        googleResponse(['id' => 'acl-retry-copy']), googleResponse($form), googleResponse(['permissions' => [$permission]]),
        ['status' => 403, 'body' => 'SENTINEL_SECRET_BODY'],
    ]);
    $failure = '';
    try { new EarthquakeChecker($config, new FakeP2PQuakeClient([namedEarthquakeEvent()]), $line, new StateStore($dir . '/state.json'), new Logger($dir . '/app.log'), new GoogleFormsClient($config, $first))->run(); } catch (RuntimeException $e) { $failure = $e->getMessage(); }
    assertTrue($failure === 'Google Forms permission update failed. HTTP status: 403', 'Update failure must be classified without response-body secrets.');
    $state = readState($dir);
    assertTrue($line->attempts === [] && $state['notified'] === [], 'Failed permission update must not send or mark notified.');
    assertTrue(($state['google_forms_by_earthquake_time']['2026-07-05T12:00:00']['form_id'] ?? '') === 'acl-retry-copy', 'Copied ID must survive permission update failure.');
    $retry = new QueuedGoogleTransport([
        googleResponse(['access_token' => 'synthetic-token']), googleResponse($form), googleResponse(['permissions' => [$permission]]), googleResponse(['id' => 'published-acl']),
    ]);
    new EarthquakeChecker($config, new FakeP2PQuakeClient([namedEarthquakeEvent()]), $line, new StateStore($dir . '/state.json'), new Logger($dir . '/app.log'), new GoogleFormsClient($config, $retry))->run();
    assertTrue(mainMessageCount($line) === 1 && str_contains($line->messages[0]['text'], 'https://respond.invalid/acl-retry'), 'Retry must deliver after the same permission is updated.');
    foreach ($retry->requests as $request) assertTrue(!str_contains($request['url'], '/copy?'), 'Permission retry must not create another form.');
});

register_test('Google Forms quiet capture makes no Google requests or LINE sends', static function (): void {
    $dir = tempDir(); $line = new FakeLineWorksClient(); $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->modify('+1 day')->format('Y-m-d');
    $config = googleConfig($dir); $transport = new QueuedGoogleTransport([]);
    new EarthquakeChecker($config, new FakeP2PQuakeClient([namedEarthquakeEvent('quiet', $today . 'T03:00:00')]), $line, new StateStore($dir . '/state.json'), new Logger($dir . '/app.log'), new GoogleFormsClient($config, $transport))->run();
    assertTrue($transport->requests === [] && $line->attempts === [], 'quiet capture must neither create a form nor send a message.');
});
