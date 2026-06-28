#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Logger.php';
require_once __DIR__ . '/../src/StateStore.php';
require_once __DIR__ . '/../src/FormStockStore.php';
require_once __DIR__ . '/../src/P2PQuakeClient.php';
require_once __DIR__ . '/../src/LineWorksClient.php';
require_once __DIR__ . '/../src/EarthquakeChecker.php';

// cron / タスクスケジューラから呼ぶPHP版の入口。
// このファイルでは「準備」だけを行い、地震判定や送信の詳細は各クラスへ委譲する。
// 秘密値や実URLは標準出力へ出さず、必要最小限のエラーだけ返す。
$rootDir = dirname(__DIR__);
$storageDir = $rootDir . '/storage';

if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true)) {
    fwrite(STDERR, "Failed to create storage directory.
");
    exit(1);
}

// app.log / check.lock はGit管理外の runtime ファイル。
// ログには調査に必要な件数や理由だけを出し、token・secret・実フォームURLは出さない前提。
$logger = new Logger($storageDir . '/app.log');
$lockPath = $storageDir . '/check.lock';
$lockHandle = fopen($lockPath, 'c');

if ($lockHandle === false) {
    fwrite(STDERR, "Failed to open lock file.
");
    exit(1);
}

// 多重実行防止は重要。cronが重なると同じ地震を同時に処理し、
// 二重通知やフォームURLの二重消費につながるため、先行プロセスがあれば安全終了する。
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    $logger->info('Another check process is already running.');
    fclose($lockHandle);
    exit(0);
}

$exitCode = 0;

try {
    // 実設定はGit管理外の config.php から読む。
    // Config::load() が必須値・秘密鍵パス・テストモード条件をまとめて検証する。
    $config = Config::load($rootDir . '/config.php');

    $stateStore = new StateStore($storageDir . '/state.json');
    $p2pQuakeClient = new P2PQuakeClient($config->p2pquakeApiUrl(), 10);
    $lineWorksClient = new LineWorksClient($config);
    $formStockStore = new FormStockStore(
        $config->formStockPath(),
        $config->formImportCsvPath(),
        $config->formImportProcessedDir(),
        $config->formImportFailedDir()
    );
    // Store / Client をここで組み立て、EarthquakeChecker に実務フローを任せる。
    // P2PQuakeClient の limit は10固定。最新1件だけだと通知対象地震を見落とすため。
    $checker = new EarthquakeChecker(
        $config,
        $p2pQuakeClient,
        $lineWorksClient,
        $stateStore,
        $formStockStore,
        $logger
    );

    $checker->run();
} catch (Throwable $e) {
    // 例外時は exit code 1 で停止する。
    // 送信失敗やstate破損を握りつぶして正常終了すると、運用側が異常に気づけない。
    $exitCode = 1;
    $logger->error('Check failed.', ['error' => $e->getMessage()]);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
} finally {
    // 途中で例外が出てもロックは必ず解放する。
    // 次回cronが永久に動けなくなる事故を避けるため。
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

exit($exitCode);
