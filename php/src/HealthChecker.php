<?php
declare(strict_types=1);

final class HealthChecker
{
    // 別cronから呼ばれるヘルスチェック用の集計クラス。
    // 「health_check自身が動いた」ことと「本体check.phpが動いた痕跡」は別なので、
    // app.log 内の normal_log / warning / error を短く要約して通知する。
    private string $logPath;

    public function __construct(string $logPath)
    {
        $this->logPath = $logPath;
    }

    /** @return array{days: int, normal_log: int, warning: int, error: int, tail: array<int, string>} */
    public function summarize(int $days = 7): array
    {
        // normal_log は安否通知成功件数ではなく、正常系ログ行の数。
        // check_completed や notification_completed を数え、直近期間に本体cronが動いていたかを見る。
        $since = time() - ($days * 86400);
        $summary = [
            'days' => $days,
            'normal_log' => 0,
            'warning' => 0,
            'error' => 0,
            'tail' => [],
        ];

        if (!is_file($this->logPath)) {
            return $summary;
        }

        $lines = file($this->logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException('Failed to read app log.');
        }

        foreach ($lines as $line) {
            if (!$this->isWithinPeriod($line, $since)) {
                continue;
            }

            if (str_contains($line, ' ERROR ')) {
                $summary['error']++;
                $summary['tail'][] = $this->compressLine($line);
                continue;
            }

            if (str_contains($line, ' WARNING ') || str_contains($line, 'skipped_due_to_form_stock_out') || str_contains($line, 'form_low_stock_notice_sent')) {
                $summary['warning']++;
                continue;
            }

            // normal_log は「正常系ログ行」の件数。
            // check.phpの正常終了回数だけではなく、通知完了ログも含めて、直近期間に本体が動いた痕跡を見る。
            if (str_contains($line, 'check_completed') || str_contains($line, 'notification_completed')) {
                $summary['normal_log']++;
            }
        }

        $summary['tail'] = array_slice($summary['tail'], -3);

        return $summary;
    }

    /** @param array{days: int, normal_log: int, warning: int, error: int, tail: array<int, string>} $summary */
    public function message(array $summary): string
    {
        // チャットへ送る内容は圧縮する。
        // ログ全文を送ると秘匿値混入時の拡散リスクがあるため、error時だけ短い末尾要約を添える。
        $message = "安否確認botヘルスチェック\n"
            . '対象期間: 直近' . $summary['days'] . "日\n"
            . 'normal_log: ' . $summary['normal_log'] . "件\n"
            . 'warning: ' . $summary['warning'] . "件\n"
            . 'error: ' . $summary['error'] . '件';

        if ($summary['error'] > 0 && $summary['tail'] !== []) {
            $message .= "\n直近error:\n" . implode("\n", $summary['tail']);
        }

        return $message;
    }

    private function isWithinPeriod(string $line, int $since): bool
    {
        if (!preg_match('/^\[([^\]]+)\]/', $line, $matches)) {
            return false;
        }

        $timestamp = strtotime($matches[1]);

        return $timestamp !== false && $timestamp >= $since;
    }

    private function compressLine(string $line): string
    {
        $line = preg_replace('/\s+/', ' ', $line) ?? $line;

        return substr($line, 0, 160);
    }
}
