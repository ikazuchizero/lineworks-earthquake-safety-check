<?php
declare(strict_types=1);

final class Logger
{
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
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            fwrite(STDERR, 'Failed to create log directory: ' . $dir . PHP_EOL);
            return;
        }

        $line = '[' . date('c') . '] ' . $level . ' ' . $message;

        if ($context !== []) {
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $line .= ' ' . ($json !== false ? $json : '[context encode failed]');
        }

        file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
