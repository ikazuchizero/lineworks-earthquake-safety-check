<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Logger.php';
require_once __DIR__ . '/../src/StateStore.php';
require_once __DIR__ . '/../src/FormStockStore.php';
require_once __DIR__ . '/../src/P2PQuakeClient.php';
require_once __DIR__ . '/../src/LineWorksClient.php';
require_once __DIR__ . '/../src/EarthquakeChecker.php';
require_once __DIR__ . '/../src/SetupChecker.php';
require_once __DIR__ . '/../src/ConnectivityChecker.php';
require_once __DIR__ . '/../src/ErrorNotificationStore.php';
require_once __DIR__ . '/../src/FailureNotifier.php';
require_once __DIR__ . '/../src/HealthChecker.php';

// このファイルは、外部API・実 config.php・実 storage/forms に触らずに、
// PHP版安否確認botの重要仕様を固定するためのシナリオテストです。
// PHPUnitやComposerを使わず、Fakeクライアントと一時ディレクトリだけで
// cron本体に近い流れを通し、運用事故につながる分岐を確認します。
//
// ここで守っている主な仕様:
// - notify_scale はP2PQuakeの震度コードとして許可値だけを受け付けること。
// - config.example.php由来の REPLACE_WITH_... プレースホルダーを拒否すること。
// - HTTPレスポンスに複数のHTTP行があっても、最終ステータスを採用すること。
// - フォーム枯渇時は安否確認本文を送らず、保守通知だけを扱うこと。
// - フォーム枯渇の保守通知失敗はretryするが、本文の後追い自動送信はしないこと。
// - UNKNOWN震源は保留し、名前確定続報またはhold超過fallbackで1回だけ通知すること。
// - 通知済み地震やフォーム枯渇skip済み地震を二重通知しないこと。
// - state/forms JSONが壊れている場合は、空扱いせず安全側に停止すること。
// - setup/connectivity/failure/health系の補助チェックが、実送信なしで期待通り動くこと。
final class FakeP2PQuakeClient extends P2PQuakeClient
{
    // P2PQuake APIの代役です。
    // 速報・続報・UNKNOWN震源・空配列など、APIレスポンスとして返ってきた想定の配列を固定し、
    // ネットワークへ出ずにEarthquakeCheckerの対象選別だけを検証します。
    /** @param array<int, array<string, mixed>> $events */
    public function __construct(private array $events)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchEarthquakes(): array
    {
        return $this->events;
    }
}

final class FakeLineWorksClient extends LineWorksClient
{
    // LINE WORKS APIの代役です。
    // 実送信はせず、送信先roomと本文だけを記録します。
    // room_id === null は通常の安否確認本文、room_id !== null は保守通知として扱い、
    // フォーム枯渇時に本文を誤送信していないか、保守通知だけretryしているかを区別します。
    // failMaintenance は保守通知失敗を再現するためのフラグで、失敗通知のretry検証に使います。
    /** @var array<int, array{room_id: string|null, text: string}> */
    public array $messages = [];
    public bool $failMaintenance = false;

    public function __construct()
    {
    }

    public function sendMessage(string $text, ?string $roomId = null): void
    {
        $this->messages[] = [
            'room_id' => $roomId,
            'text' => $text,
        ];

        if ($this->failMaintenance && $roomId !== null) {
            throw new RuntimeException('fake maintenance send failure');
        }
    }
}

function assertTrue(bool $condition, string $message): void
{
    // 外部テストフレームワークを入れずに、失敗した仕様を即座に分かるようにする最小assertです。
    // 失敗時はRuntimeExceptionで止め、$testsの表示名とmessageから壊れた仕様を追えるようにします。
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function tempDir(): string
{
    // 各テストは独立した一時ディレクトリで完結させます。
    // 実運用の php/storage、php/forms、実configには触れず、
    // state/forms JSONの作成・破損・保存を安全に再現するためです。
    $dir = sys_get_temp_dir() . '/lineworks_earthquake_test_' . bin2hex(random_bytes(4));
    if (!mkdir($dir, 0775, true)) {
        throw new RuntimeException('Failed to create temporary test directory.');
    }

    return $dir;
}

/** @param array<string, mixed> $overrides */
function writeConfig(string $dir, array $overrides = []): string
{
    // テスト専用の最小configを生成します。
    // 実config.phpは読まず、秘匿値相当の項目もすべてダミー値にして、
    // Config::load()や各Checkerのパス解決だけを確認できる状態を作ります。
    // $overrides は、プレースホルダー拒否・固定form_urlモード・不正notify_scaleなど、
    // 特定の設定ミスを再現するために使います。
    $privateKeyPath = $dir . '/private.key';
    file_put_contents($privateKeyPath, 'dummy-key');

    $config = array_merge([
        'notify_scale' => 45,
        'unknown_hypocenter_hold_seconds' => 600,
        'p2pquake_api_url' => 'https://example.invalid/quake',
        'lineworks_token_url' => 'https://example.invalid/token',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'service_account' => 'service-account',
        'bot_id' => 'bot-id',
        'room_id' => 'room-id',
        'private_key_path' => $privateKeyPath,
        'form_stock_enabled' => true,
        'form_url' => '',
        'form_stock_path' => $dir . '/forms.json',
        'form_import_csv_path' => $dir . '/forms.csv',
        'form_import_processed_dir' => $dir . '/processed',
        'form_import_failed_dir' => $dir . '/failed',
        'form_low_stock_threshold' => 10,
        'form_low_stock_room_id' => 'maintenance-room-id',
    ], $overrides);

    $path = $dir . '/config.php';
    file_put_contents($path, '<?php return ' . var_export($config, true) . ';' . PHP_EOL);

    return $path;
}

function createRuntimeDirs(string $dir): void
{
    // setup_checkや通常処理が期待する実行時ディレクトリを一時領域に作ります。
    // storageはstate/log等、forms/processed/failedはCSV取り込み先の代替です。
    // 本物のディレクトリ権限や運用データへ影響を出さないため、必ずtempDir配下だけを使います。
    foreach ([
        $dir . '/storage',
        $dir . '/forms',
        $dir . '/processed',
        $dir . '/failed',
    ] as $path) {
        if (!is_dir($path) && !mkdir($path, 0775, true)) {
            throw new RuntimeException('Failed to create runtime test directory.');
        }
    }
}

function loadConfigForTest(array $overrides = []): Config
{
    // Config::load()単体の検証用helperです。
    // 実configを使わず、writeConfig()で作った一時configだけを読み込ませます。
    $dir = tempDir();
    return Config::load(writeConfig($dir, $overrides));
}

function expectConfigFailure(array $overrides, string $message): void
{
    // 「設定ミスなら安全に止まる」ことを確認するhelperです。
    // Config::load()が通ってしまうと、本番でプレースホルダーや危険な閾値が動く可能性があるため、
    // 期待通りRuntimeExceptionになることをテスト名付きで固定します。
    try {
        loadConfigForTest($overrides);
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException($message);
}

function testNotifyScaleValidation(): void
{
    // 前提: notify_scale は表示文字列ではなく、P2PQuakeの震度コードです。
    // 操作: 許可値の0/45を読み込み、不正な負数・小数・未定義コードを読み込ませます。
    // 期待: 検証用の0と震度5弱相当の45は通り、危険な値はConfig::load()で止まります。
    // 防ぐ事故: 存在しない閾値や型のゆるい値により、通知漏れ・過剰通知が起きること。
    loadConfigForTest(['notify_scale' => 0]);
    loadConfigForTest(['notify_scale' => 45]);
    expectConfigFailure(['notify_scale' => -1], 'negative notify_scale must fail.');
    expectConfigFailure(['notify_scale' => 45.5], 'decimal notify_scale must fail.');
    expectConfigFailure(['notify_scale' => 999], 'unknown notify_scale must fail.');
}

function testPlaceholderValidation(): void
{
    // 前提: config.example.phpには実値の代わりに REPLACE_WITH_... を置く場合があります。
    // 操作: room/client/form_urlにプレースホルダーを残したconfigを読み込ませます。
    // 期待: 非空文字列でも実設定としては扱わず、設定検証で停止します。
    // 防ぐ事故: 初回設置時に置換忘れのままcronが動き、送信不能や誤った固定URL運用になること。
    expectConfigFailure(
        ['form_low_stock_room_id' => 'REPLACE_WITH_FORM_LOW_STOCK_ROOM_ID'],
        'placeholder form_low_stock_room_id must fail.'
    );
    expectConfigFailure(
        ['client_id' => 'REPLACE_WITH_CLIENT_ID'],
        'placeholder client_id must fail.'
    );
    expectConfigFailure(
        [
            'form_stock_enabled' => false,
            'form_url' => 'REPLACE_WITH_FORM_URL',
        ],
        'placeholder form_url must fail when form stock is disabled.'
    );
}

function testLastHttpStatusCodeWins(): void
{
    // 前提: HTTPヘッダーには 100 Continue やリダイレクトなど、複数のHTTPステータス行が出ることがあります。
    // 操作: LINE WORKS/P2PQuakeそれぞれのステータス抽出処理へ複数行ヘッダーを渡します。
    // 期待: 最初の中間ステータスではなく、最後のHTTPステータスを採用します。
    // 防ぐ事故: 実際は最終200なのに中間ステータスを見て失敗扱いすること。
    $lineWorks = (new ReflectionClass(LineWorksClient::class))->newInstanceWithoutConstructor();
    $lineWorksMethod = new ReflectionMethod(LineWorksClient::class, 'statusCodeFromHeaders');
    $lineWorksMethod->setAccessible(true);
    assertTrue(
        $lineWorksMethod->invoke($lineWorks, ['HTTP/1.1 100 Continue', 'HTTP/1.1 200 OK']) === 200,
        'LINE WORKS status parser must use the last HTTP status line.'
    );

    $p2p = (new ReflectionClass(P2PQuakeClient::class))->newInstanceWithoutConstructor();
    $p2pMethod = new ReflectionMethod(P2PQuakeClient::class, 'statusCodeFromHeaders');
    $p2pMethod->setAccessible(true);
    assertTrue(
        $p2pMethod->invoke($p2p, ['HTTP/1.1 302 Found', 'HTTP/1.1 200 OK']) === 200,
        'P2PQuake status parser must use the last HTTP status line.'
    );
}

/** @return array<string, mixed> */
function earthquakeEvent(string $id = 'event-1'): array
{
    // 通常の通知対象地震を作るfixtureです。
    // maxScaleはnotify_scale=45に一致し、震源地名もあるため、フォーム在庫があれば本文送信対象になります。
    return [
        'id' => $id,
        'earthquake' => [
            'time' => '2026-07-05T12:00:00',
            'maxScale' => 45,
            'hypocenter' => [
                'name' => 'Test Hypocenter',
            ],
        ],
    ];
}

/** @return array<string, mixed> */
function unknownEarthquakeEvent(string $id = 'unknown-event-1', string $time = '2026-07-05T12:00:00'): array
{
    // 震源地名が空のイベントを作るfixtureです。
    // 本体ではUNKNOWNとして扱われ、初回は即通知せずpendingへ入る仕様を検証します。
    return [
        'id' => $id,
        'earthquake' => [
            'time' => $time,
            'maxScale' => 45,
            'hypocenter' => [
                'name' => '',
            ],
        ],
    ];
}

/** @return array<string, mixed> */
function namedEarthquakeEvent(string $id = 'named-event-1', string $time = '2026-07-05T12:00:00', string $name = 'Named Hypocenter'): array
{
    // 震源地名ありの続報を作るfixtureです。
    // UNKNOWN pending中に同じearthquake_timeで届いた場合、UNKNOWNではなくこちらを優先することを確認します。
    return [
        'id' => $id,
        'earthquake' => [
            'time' => $time,
            'maxScale' => 45,
            'hypocenter' => [
                'name' => $name,
            ],
        ],
    ];
}

/** @param array<int, array<string, mixed>>|null $events */
function buildChecker(string $dir, FakeLineWorksClient $lineWorks, ?array $events = null): EarthquakeChecker
{
    // EarthquakeCheckerを本番と同じ依存構成で組み立てつつ、
    // P2PQuake/LINE WORKS/状態ファイルだけをFakeと一時ファイルに差し替えるhelperです。
    // この形にすることで、selectNotificationTargets()だけの単体検証ではなく、
    // CSV取り込み、state保存、フォーム消費、保守通知retryまで本体フローとして確認できます。
    // ただし外部APIや実運用ファイルには一切触れません。
    $config = Config::load(writeConfig($dir));
    $stateStore = new StateStore($dir . '/state.json');
    $formStockStore = new FormStockStore(
        $dir . '/forms.json',
        $dir . '/forms.csv',
        $dir . '/processed',
        $dir . '/failed'
    );

    return new EarthquakeChecker(
        $config,
        new FakeP2PQuakeClient($events ?? [earthquakeEvent()]),
        $lineWorks,
        $stateStore,
        $formStockStore,
        new Logger($dir . '/app.log')
    );
}

function readState(string $dir): array
{
    // テスト後のstate.jsonを読み、通知済み・pending UNKNOWN・stock out skipなどの状態遷移を確認します。
    // json_decodeできない場合はテスト失敗にし、破損JSONを空扱いしていないことも補助的に守ります。
    $json = file_get_contents($dir . '/state.json');
    assertTrue($json !== false, 'state.json must exist.');
    $state = json_decode($json, true);
    assertTrue(is_array($state), 'state.json must decode to an array.');

    return $state;
}

function writeState(string $dir, array $state): void
{
    // pending時刻の巻き戻しや、既存stateを持つ再実行を再現するためのhelperです。
    // 本体の保存処理そのものを検証する場面ではなく、次のcron実行前の状態を作る用途に限定します。
    file_put_contents($dir . '/state.json', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
}

function writeAvailableForms(string $dir, int $count = 1): void
{
    // フォーム在庫ありの状態を作るhelperです。
    // URLは実フォームではなく example.invalid のダミーで、送信本文の組み立てとused化だけを検証します。
    // countを変えることで、通常送信・複数回送信・低在庫境界の前提を作れます。
    $forms = [];
    for ($i = 1; $i <= $count; $i++) {
        $forms[] = [
            'url' => 'https://example.invalid/form/' . $i,
            'status' => 'available',
            'imported_at' => gmdate('c'),
            'used_at' => null,
            'dedupe_key' => null,
        ];
    }

    file_put_contents($dir . '/forms.json', json_encode([
        'forms' => $forms,
        'low_stock_notified' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
}

function mainMessageCount(FakeLineWorksClient $lineWorks): int
{
    // FakeLineWorksClientに記録された送信のうち、安否確認本文だけを数えるhelperです。
    // room_id !== null の保守通知を混ぜて数えると、フォーム枯渇時に「本文を送っていない」ことを
    // 誤判定するため、通常通知と保守通知を明確に分けます。
    $count = 0;
    foreach ($lineWorks->messages as $message) {
        if ($message['room_id'] === null) {
            $count++;
        }
    }

    return $count;
}

function testStockOutNoticeSuccessIsNotRepeated(): void
{
    // 前提: 対象地震はあるが、forms.jsonにavailableフォームがない状態です。
    // 操作: 1回目のcron相当処理でフォーム枯渇保守通知を成功させ、同じ地震でもう一度実行します。
    // 期待: 安否確認本文は送られず、保守通知は1回だけ送られ、stateにはnotice_status=sentが残ります。
    // 防ぐ事故: フォームがない同一地震について、cronごとに枯渇通知が連投されること。
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();

    buildChecker($dir, $lineWorks)->run();
    assertTrue(count($lineWorks->messages) === 1, 'stock out notice must be sent once.');
    $state = readState($dir);
    $record = reset($state['skipped_due_to_form_stock_out']);
    assertTrue(($record['notice_status'] ?? null) === 'sent', 'stock out notice status must be sent.');

    buildChecker($dir, $lineWorks)->run();
    assertTrue(count($lineWorks->messages) === 1, 'sent stock out notice must not be repeated.');
}

function testStockOutNoticeFailureIsRetriedWithoutBodyNotification(): void
{
    // 前提: 対象地震はあるがフォーム在庫0件で、さらに保守通知の送信だけが失敗する状態です。
    // 操作: 1回目は保守通知失敗、2回目は保守通知成功として同じ地震を再実行します。
    // 期待: retryされるのは保守通知だけで、安否確認本文は一度も送られません。
    // 防ぐ事故: 管理通知失敗のretry時に、フォームなしのまま本文を誤送信したり、retry不能で未達になること。
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $lineWorks->failMaintenance = true;

    buildChecker($dir, $lineWorks)->run();
    assertTrue(count($lineWorks->messages) === 1, 'failed stock out notice must be attempted once.');
    $state = readState($dir);
    $record = reset($state['skipped_due_to_form_stock_out']);
    assertTrue(($record['notice_status'] ?? null) === 'failed', 'stock out notice status must be failed.');

    $lineWorks->failMaintenance = false;
    buildChecker($dir, $lineWorks)->run();
    assertTrue(count($lineWorks->messages) === 2, 'failed stock out notice must be retried once.');
    foreach ($lineWorks->messages as $message) {
        assertTrue($message['room_id'] === 'maintenance-room-id', 'stock out scenario must not send the main safety message.');
    }

    $state = readState($dir);
    $record = reset($state['skipped_due_to_form_stock_out']);
    assertTrue(($record['notice_status'] ?? null) === 'sent', 'retried stock out notice status must be sent.');
}

function testStockOutNoticeFailureIsRetriedAfterEventDisappears(): void
{
    // 前提: フォーム枯渇の保守通知が失敗した後、次回取得結果から対象地震が消えた状態です。
    // 操作: 失敗済みstateだけを残して、P2PQuakeイベント空配列で再実行します。
    // 期待: state上の未達レコードから保守通知だけを再試行し、成功後は再通知しません。
    // 防ぐ事故: P2PQuakeの取得上限や時間経過でイベントが消えたために、管理通知未達のまま放置されること。
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $lineWorks->failMaintenance = true;

    buildChecker($dir, $lineWorks, [earthquakeEvent()])->run();
    assertTrue(count($lineWorks->messages) === 1, 'failed stock out notice must be attempted once.');

    $lineWorks->failMaintenance = false;
    buildChecker($dir, $lineWorks, [])->run();
    assertTrue(count($lineWorks->messages) === 2, 'failed stock out notice must be retried even when the event disappeared.');
    assertTrue($lineWorks->messages[1]['room_id'] === 'maintenance-room-id', 'retry after disappeared event must be maintenance-only.');

    writeAvailableForms($dir);
    buildChecker($dir, $lineWorks, [])->run();
    assertTrue(count($lineWorks->messages) === 2, 'sent stock out notice retry must not be repeated.');

    $state = readState($dir);
    $record = reset($state['skipped_due_to_form_stock_out']);
    assertTrue(($record['notice_status'] ?? null) === 'sent', 'retried disappeared-event notice status must be sent.');
}

function testStockOutSkippedEventIsNotSentAfterFormsAreReplenished(): void
{
    // 前提: フォーム枯渇で対象地震をskipped_due_to_form_stock_outに保存済みです。
    // 操作: 後からフォーム在庫を補充し、同じ地震が再び取得された状態で実行します。
    // 期待: 補充後でも安否確認本文は後追い送信せず、必要なら保守通知retryだけを行います。
    // 防ぐ事故: 「枯渇時に手動対応扱いにした地震」を、フォーム補充後にbotが自動送信してしまうこと。
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $lineWorks->failMaintenance = true;

    buildChecker($dir, $lineWorks, [earthquakeEvent()])->run();

    writeAvailableForms($dir);

    $lineWorks->failMaintenance = false;
    buildChecker($dir, $lineWorks, [earthquakeEvent()])->run();

    assertTrue(count($lineWorks->messages) === 2, 'stock out skipped event must not be sent as a body notification after forms are replenished.');
    foreach ($lineWorks->messages as $message) {
        assertTrue($message['room_id'] === 'maintenance-room-id', 'replenished stock out retry must remain maintenance-only.');
    }
}

function testUnknownHypocenterInitialPending(): void
{
    // 前提: 通知対象震度だが、hypocenter.nameが空でUNKNOWN扱いになる初回イベントです。
    // 操作: フォーム在庫がある状態でUNKNOWNイベントを1回だけ処理します。
    // 期待: 安否確認本文は送らず、pending_unknown_by_earthquake_timeへ保留情報を保存します。
    // 防ぐ事故: 震源地名あり続報が来る前にUNKNOWNで即通知し、後続の名前あり情報と二重送信すること。
    $dir = tempDir();
    writeAvailableForms($dir);
    $lineWorks = new FakeLineWorksClient();

    buildChecker($dir, $lineWorks, [unknownEarthquakeEvent()])->run();

    assertTrue(mainMessageCount($lineWorks) === 0, 'initial UNKNOWN hypocenter must not send the main safety message.');
    $state = readState($dir);
    assertTrue(isset($state['pending_unknown_by_earthquake_time']['2026-07-05T12:00:00']), 'initial UNKNOWN hypocenter must be stored as pending.');
    assertTrue($state['notified'] === [], 'initial UNKNOWN hypocenter must not be marked as notified.');
}

function testUnknownHypocenterFallbackAfterHold(): void
{
    // 前提: UNKNOWNイベントを保留済みで、hold時間を過ぎても名前確定続報が来ない状態です。
    // 操作: pendingのfirst_seen_atを過去に戻し、UNKNOWNイベントを再処理します。
    // 期待: UNKNOWNのままfallback通知を1回だけ送り、notifiedへ保存してpendingを消します。
    // 防ぐ事故: 震源地名が確定しない地震を永遠に通知しないこと、またfallback後に再通知すること。
    $dir = tempDir();
    writeAvailableForms($dir, 2);
    $lineWorks = new FakeLineWorksClient();

    buildChecker($dir, $lineWorks, [unknownEarthquakeEvent()])->run();
    $state = readState($dir);
    $state['pending_unknown_by_earthquake_time']['2026-07-05T12:00:00']['first_seen_at'] = gmdate('c', time() - 601);
    writeState($dir, $state);

    buildChecker($dir, $lineWorks, [unknownEarthquakeEvent()])->run();
    assertTrue(mainMessageCount($lineWorks) === 1, 'expired UNKNOWN pending must send one fallback main safety message.');

    $state = readState($dir);
    assertTrue($state['notified'] !== [], 'expired UNKNOWN fallback must be marked as notified.');
    assertTrue(!isset($state['pending_unknown_by_earthquake_time']['2026-07-05T12:00:00']), 'expired UNKNOWN fallback must clear pending state after notification.');

    buildChecker($dir, $lineWorks, [unknownEarthquakeEvent()])->run();
    assertTrue(mainMessageCount($lineWorks) === 1, 'notified UNKNOWN fallback must not be sent again.');
}

function testNamedHypocenterReplacesPendingUnknown(): void
{
    // 前提: 同じearthquake_timeのUNKNOWNを保留中に、震源地名あり続報が届いた状態です。
    // 操作: UNKNOWN初回でpendingを作り、その後に名前あり候補を処理します。
    // 期待: 送るのは名前あり候補1件だけで、本文にもUNKNOWNではなく震源地名が入ります。
    // 防ぐ事故: UNKNOWN版と名前あり続報版を別地震として二重通知すること。
    $dir = tempDir();
    writeAvailableForms($dir, 2);
    $lineWorks = new FakeLineWorksClient();

    buildChecker($dir, $lineWorks, [unknownEarthquakeEvent('unknown-event-1', '2026-07-05T12:30:00')])->run();
    buildChecker($dir, $lineWorks, [namedEarthquakeEvent('named-event-1', '2026-07-05T12:30:00', 'Named Hypocenter')])->run();

    assertTrue(mainMessageCount($lineWorks) === 1, 'named hypocenter continuation must send exactly one main safety message.');
    assertTrue(str_contains($lineWorks->messages[0]['text'], 'Named Hypocenter'), 'named hypocenter continuation must use the named hypocenter candidate.');
    assertTrue(!str_contains($lineWorks->messages[0]['text'], 'UNKNOWN'), 'named hypocenter continuation must not send the UNKNOWN candidate.');
}

function testNotifiedEarthquakeIsNotRepeated(): void
{
    // 前提: 震源地名ありの対象地震を一度正常送信できる状態です。
    // 操作: 同じevent/dedupe_key/earthquake_timeの地震を2回処理します。
    // 期待: 1回目だけ安否確認本文を送り、2回目はnotified状態によりskipします。
    // 防ぐ事故: P2PQuakeの続報やcron再実行で、同じ地震を繰り返し通知すること。
    $dir = tempDir();
    writeAvailableForms($dir, 2);
    $lineWorks = new FakeLineWorksClient();

    buildChecker($dir, $lineWorks, [namedEarthquakeEvent()])->run();
    assertTrue(mainMessageCount($lineWorks) === 1, 'first named earthquake must send one main safety message.');

    buildChecker($dir, $lineWorks, [namedEarthquakeEvent()])->run();
    assertTrue(mainMessageCount($lineWorks) === 1, 'notified earthquake must not be sent again.');
}

function testInvalidStateJsonStops(): void
{
    // 前提: state.jsonが不正JSONで、通知済み履歴を信用できない状態です。
    // 操作: 壊れたstate.jsonを置いたまま、通常なら通知対象になる地震を処理します。
    // 期待: 例外で停止し、安否確認本文は送られません。
    // 防ぐ事故: 壊れたstateを空扱いして、過去通知済み地震を再通知すること。
    $dir = tempDir();
    writeAvailableForms($dir);
    file_put_contents($dir . '/state.json', '{invalid json');
    $lineWorks = new FakeLineWorksClient();

    try {
        buildChecker($dir, $lineWorks, [namedEarthquakeEvent()])->run();
    } catch (RuntimeException) {
        assertTrue(mainMessageCount($lineWorks) === 0, 'invalid state.json must stop before sending the main safety message.');
        return;
    }

    throw new RuntimeException('invalid state.json must not be treated as an empty state.');
}

function testInvalidFormsJsonStops(): void
{
    // 前提: forms.jsonが不正JSONで、フォーム在庫の状態を信用できない状態です。
    // 操作: 壊れたforms.jsonを置いたまま、通常なら通知対象になる地震を処理します。
    // 期待: 例外で停止し、本文も保守通知も送られません。
    // 防ぐ事故: 壊れたforms.jsonを空在庫や利用可能在庫として誤判定し、誤送信や誤った枯渇通知を行うこと。
    $dir = tempDir();
    file_put_contents($dir . '/forms.json', '{invalid json');
    $lineWorks = new FakeLineWorksClient();

    try {
        buildChecker($dir, $lineWorks, [namedEarthquakeEvent()])->run();
    } catch (RuntimeException) {
        assertTrue(count($lineWorks->messages) === 0, 'invalid forms.json must stop before any LINE WORKS message.');
        return;
    }

    throw new RuntimeException('invalid forms.json must not be treated as empty stock.');
}

function testSetupCheckerSuccess(): void
{
    // 前提: 初回設置に必要なconfig、秘密鍵ダミー、storage/forms系ディレクトリが揃っています。
    // 操作: SetupCheckerを一時ディレクトリのルートに対して実行します。
    // 期待: すべてOKとなり、setup_checkとして成功判定できます。
    // 防ぐ事故: 正常構成にもかかわらずsetup_checkが過剰にNGを出し、設置作業を止めること。
    $dir = tempDir();
    createRuntimeDirs($dir);
    writeConfig($dir);

    $checker = new SetupChecker($dir);
    $results = $checker->run();

    assertTrue($checker->isOk($results), 'setup checker must pass when required files and directories exist.');
}

function testSetupCheckerMissingConfig(): void
{
    // 前提: 実行時ディレクトリはあるが、config.phpだけが欠落している初回設置ミスです。
    // 操作: SetupCheckerを実行し、最初の必須ファイルチェック結果を確認します。
    // 期待: config.php欠落をNGとして報告します。
    // 防ぐ事故: configなしでcronを動かして、後段で分かりにくい失敗になること。
    $dir = tempDir();
    createRuntimeDirs($dir);

    $checker = new SetupChecker($dir);
    $results = $checker->run();

    assertTrue(!$checker->isOk($results), 'setup checker must fail when config.php is missing.');
    assertTrue(($results[0]['status'] ?? null) === 'NG', 'missing config.php must be reported as NG.');
}

function testSetupCheckerMissingDirectory(): void
{
    // 前提: configはあるが、フォームストック有効時に必要なforms系ディレクトリが欠落しています。
    // 操作: storageだけ作った状態でSetupCheckerを実行します。
    // 期待: forms_dirがNGになり、配置漏れとして検出できます。
    // 防ぐ事故: CSV移動先やフォーム在庫置き場がないまま運用し、取り込みや通知時に失敗すること。
    $dir = tempDir();
    writeConfig($dir);
    mkdir($dir . '/storage', 0775, true);

    $checker = new SetupChecker($dir);
    $results = $checker->run();
    $byName = [];
    foreach ($results as $result) {
        $byName[$result['name']] = $result;
    }

    assertTrue(($byName['forms_dir']['status'] ?? null) === 'NG', 'setup checker must fail when forms directory is missing.');
}

function testSetupCheckerSkipsFormDirectoriesWhenStockDisabled(): void
{
    // 前提: form_stock_enabled=false はフォームストックを使わず、固定form_urlで検証する例外的な構成です。
    // 操作: forms/processed/failed/forms.json保存先を作らず、固定form_urlだけを設定してSetupCheckerを実行します。
    // 期待: フォームストック用ディレクトリを必須扱いせず、setup_checkはOKになります。
    // 防ぐ事故: Config::load()では有効な固定URLモードなのに、setup_checkだけが設定モードと矛盾したNGを出すこと。
    $dir = tempDir();
    mkdir($dir . '/storage', 0775, true);
    writeConfig($dir, [
        'form_stock_enabled' => false,
        'form_url' => 'https://example.invalid/fixed-form',
    ]);

    $checker = new SetupChecker($dir);
    $results = $checker->run();

    assertTrue($checker->isOk($results), 'setup checker must pass without form stock directories when form stock is disabled.');
}

function testSetupCheckerNotWritableDirectory(): void
{
    // 前提: 必要ディレクトリは存在するが、storageが書き込み不可になっている可能性を再現します。
    // 操作: OSが権限変更を反映できる場合だけ、storageを書き込み不可にしてSetupCheckerを実行します。
    // 期待: not_writableとして検出します。権限変更を反映しない環境ではテストを安全にスキップします。
    // 防ぐ事故: state/logを書けない状態でcronを動かし、通知済み保存や障害調査ログが残らないこと。
    $dir = tempDir();
    createRuntimeDirs($dir);
    writeConfig($dir);
    chmod($dir . '/storage', 0555);
    clearstatcache(true, $dir . '/storage');

    if (is_writable($dir . '/storage')) {
        chmod($dir . '/storage', 0775);
        return;
    }

    $checker = new SetupChecker($dir);
    $results = $checker->run();
    chmod($dir . '/storage', 0775);

    $byName = [];
    foreach ($results as $result) {
        $byName[$result['name']] = $result;
    }

    assertTrue(($byName['storage_dir']['reason'] ?? null) === 'not_writable', 'setup checker must detect not writable storage directory when the OS enforces permissions.');
}

function testConnectivityCheckerUsesShortTestMessage(): void
{
    // 前提: 初回疎通チェックはLINE WORKSへ短いテスト文を送るだけで、安否確認本文ではありません。
    // 操作: FakeLineWorksClientでConnectivityCheckerを実行します。
    // 期待: 保守通知先roomへ1件だけ送られ、地震通知本文に見える文言は含みません。
    // 防ぐ事故: 疎通確認が本物の安否確認通知と誤認され、利用者を混乱させること。
    $lineWorks = new FakeLineWorksClient();
    $checker = new ConnectivityChecker($lineWorks, 'maintenance-room-id');

    $checker->run();

    assertTrue(count($lineWorks->messages) === 1, 'connectivity checker must send one test message.');
    assertTrue($lineWorks->messages[0]['room_id'] === 'maintenance-room-id', 'connectivity checker must use the configured maintenance room.');
    assertTrue(!str_contains($lineWorks->messages[0]['text'], '【地震情報】'), 'connectivity checker must not send the earthquake safety message body.');
}

function testFailureNotifierSuppressesRepeatedError(): void
{
    // 前提: check.php本体で同じ例外がcronごとに繰り返し発生する可能性があります。
    // 操作: 同じエラー種別とメッセージでFailureNotifierを2回呼びます。
    // 期待: 1回目だけ保守通知し、2回目はfingerprintにより抑止します。
    // 防ぐ事故: 同一障害が直るまで、保守チャットへ毎分同じ失敗通知を連投すること。
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $notifier = new FailureNotifier(
        $lineWorks,
        new ErrorNotificationStore($dir . '/error_notifications.json'),
        'maintenance-room-id'
    );

    $error = new RuntimeException('same failure');
    assertTrue($notifier->notify($error, 'check_failed') === true, 'first failure notification must be sent.');
    assertTrue($notifier->notify($error, 'check_failed') === false, 'same failure notification must be suppressed.');
    assertTrue(count($lineWorks->messages) === 1, 'same failure must not be sent repeatedly.');
}

function testFailureNotifierSkipsLineWorksError(): void
{
    // 前提: LINE WORKS送信そのものが失敗している場合、同じLINE WORKSへ失敗通知を送ると再帰します。
    // 操作: LINE WORKS送信失敗を示す例外メッセージでFailureNotifierを呼びます。
    // 期待: 通知を試みず、FakeLineWorksClientにも送信記録を残しません。
    // 防ぐ事故: LINE WORKS障害時に失敗通知がさらに失敗し、ログや通知が再帰的に膨らむこと。
    $dir = tempDir();
    $lineWorks = new FakeLineWorksClient();
    $notifier = new FailureNotifier(
        $lineWorks,
        new ErrorNotificationStore($dir . '/error_notifications.json'),
        'maintenance-room-id'
    );

    assertTrue($notifier->notify(new RuntimeException('LINE WORKS message send failed.'), 'check_failed') === false, 'LINE WORKS failures must not trigger recursive LINE WORKS notifications.');
    assertTrue(count($lineWorks->messages) === 0, 'LINE WORKS failure notification must be skipped.');
}

function testHealthCheckerSummarizesLog(): void
{
    // 前提: health_checkはログ全文ではなく、直近ログの件数要約を保守通知へ送ります。
    // 操作: normal/warning/error相当のログ行を作り、HealthCheckerで集計します。
    // 期待: normal_logは安否通知成功件数ではなく、本体cronの正常系ログ行数として数えます。
    // 防ぐ事故: health_checkの「normal_log」を安否通知成功数と誤解し、本体cron監視と通知件数監視を混同すること。
    $dir = tempDir();
    $now = date('c');
    file_put_contents($dir . '/app.log', implode(PHP_EOL, [
        '[' . $now . '] INFO check_completed',
        '[' . $now . '] INFO form_low_stock_notice_sent {"available_count":1}',
        '[' . $now . '] ERROR Check failed. {"error":"safe"}',
    ]) . PHP_EOL);

    $checker = new HealthChecker($dir . '/app.log');
    $summary = $checker->summarize(7);
    $message = $checker->message($summary);

    assertTrue($summary['normal_log'] === 1, 'health checker must count normal log lines.');
    assertTrue($summary['warning'] === 1, 'health checker must count warning-like lines.');
    assertTrue($summary['error'] === 1, 'health checker must count error lines.');
    assertTrue(str_contains($message, 'normal_log: 1件'), 'health check message must include normal log count.');
    assertTrue(str_contains($message, 'error: 1件'), 'health check message must include error count.');
}

$tests = [
    // ここがこのファイルで実行されるテストの唯一の一覧です。
    // 新しいテスト関数を追加しても、この配列に入れない限り実行されません。
    // 表示名は、失敗時に「どの仕様が壊れたか」を人間がすぐ追える名前にします。
    'notify_scale validation' => 'testNotifyScaleValidation',
    'placeholder validation' => 'testPlaceholderValidation',
    'last HTTP status code wins' => 'testLastHttpStatusCodeWins',
    'stock out notice success is not repeated' => 'testStockOutNoticeSuccessIsNotRepeated',
    'stock out notice failure is retried without body notification' => 'testStockOutNoticeFailureIsRetriedWithoutBodyNotification',
    'stock out notice failure is retried after event disappears' => 'testStockOutNoticeFailureIsRetriedAfterEventDisappears',
    'stock out skipped event is not sent after forms are replenished' => 'testStockOutSkippedEventIsNotSentAfterFormsAreReplenished',
    'UNKNOWN hypocenter initial pending' => 'testUnknownHypocenterInitialPending',
    'UNKNOWN hypocenter fallback after hold' => 'testUnknownHypocenterFallbackAfterHold',
    'named hypocenter replaces pending UNKNOWN' => 'testNamedHypocenterReplacesPendingUnknown',
    'notified earthquake is not repeated' => 'testNotifiedEarthquakeIsNotRepeated',
    'invalid state JSON stops' => 'testInvalidStateJsonStops',
    'invalid forms JSON stops' => 'testInvalidFormsJsonStops',
    'setup checker success' => 'testSetupCheckerSuccess',
    'setup checker missing config' => 'testSetupCheckerMissingConfig',
    'setup checker missing directory' => 'testSetupCheckerMissingDirectory',
    'setup checker skips form directories when stock disabled' => 'testSetupCheckerSkipsFormDirectoriesWhenStockDisabled',
    'setup checker not writable directory' => 'testSetupCheckerNotWritableDirectory',
    'connectivity checker uses short test message' => 'testConnectivityCheckerUsesShortTestMessage',
    'failure notifier suppresses repeated error' => 'testFailureNotifierSuppressesRepeatedError',
    'failure notifier skips LINE WORKS error' => 'testFailureNotifierSkipsLineWorksError',
    'health checker summarizes log' => 'testHealthCheckerSummarizesLog',
];

foreach ($tests as $name => $test) {
    $test();
    echo 'OK: ' . $name . PHP_EOL;
}

echo 'ALL TESTS PASSED' . PHP_EOL;
