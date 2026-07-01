<?php
declare(strict_types=1);

final class Logger
{
    // app.log へ実行状況を書き出すための最小ロガー。
    // ログファイルはGit管理外だが、外部共有時は秘密値や実URLが混ざっていないか必ず確認する。
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        // ログには件数・skip理由・dedupe_keyなど調査に必要な情報だけを渡す運用。
        // token、secret、private key、実フォームURL、room_idなどは呼び出し側で入れないこと。
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            fwrite(STDERR, 'Failed to create log directory: ' . $dir . PHP_EOL);
            return;
        }

        $line = '[' . date('c') . '] ' . $level . ' ' . $message;

        if ($context !== []) {
            // contextは障害調査に便利だが、入れた値はそのままファイルへ残る。
            // 新しいログ項目を追加するときは、秘密情報や実URLでないことを確認する。
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $line .= ' ' . ($json !== false ? $json : '[context encode failed]');
        }

        file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
