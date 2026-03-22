<?php
declare(strict_types=1);

if (!function_exists('activity_base_dir')) {
    function activity_base_dir(): string
    {
        $dir = dirname(__DIR__) . '/storage/activity';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}

if (!function_exists('activity_safe_uid')) {
    function activity_safe_uid(string $uid): string
    {
        $uid = trim($uid);
        $uid = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $uid);
        return $uid !== '' ? $uid : 'unknown';
    }
}

if (!function_exists('activity_summary_path')) {
    function activity_summary_path(string $uid): string
    {
        return activity_base_dir() . '/summary_' . activity_safe_uid($uid) . '.json';
    }
}

if (!function_exists('activity_attempts_path')) {
    function activity_attempts_path(string $uid): string
    {
        return activity_base_dir() . '/attempts_' . activity_safe_uid($uid) . '.json';
    }
}

if (!function_exists('activity_now_iso')) {
    function activity_now_iso(): string
    {
        return gmdate('c');
    }
}

if (!function_exists('activity_read_json')) {
    function activity_read_json(string $path, $default)
    {
        if (!is_file($path)) {
            return $default;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return $default;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $default;
    }
}

if (!function_exists('activity_write_json')) {
    function activity_write_json(string $path, array $data): void
    {
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if (!is_string($json)) {
            return;
        }

        $fp = @fopen($path, 'c+');
        if (!$fp) {
            return;
        }

        try {
            if (@flock($fp, LOCK_EX)) {
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, $json);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }
}

if (!function_exists('activity_default_summary')) {
    function activity_default_summary(string $uid): array
    {
        return [
            'user_id' => $uid,
            'last_login_at' => null,
            'last_seen_at' => null,
            'last_page' => '',
            'total_site_seconds' => 0,
            'tests_started' => 0,
            'tests_finished' => 0,
            'total_correct_answers' => 0,
            'total_wrong_answers' => 0,
            'last_test_at' => null,
        ];
    }
}

if (!function_exists('activity_get_user_summary')) {
    function activity_get_user_summary(string $uid): array
    {
        $uid = trim($uid);
        $summary = activity_read_json(activity_summary_path($uid), activity_default_summary($uid));

        foreach (activity_default_summary($uid) as $k => $v) {
            if (!array_key_exists($k, $summary)) {
                $summary[$k] = $v;
            }
        }

        $summary['total_site_seconds'] = (int)($summary['total_site_seconds'] ?? 0);
        $summary['tests_started'] = (int)($summary['tests_started'] ?? 0);
        $summary['tests_finished'] = (int)($summary['tests_finished'] ?? 0);
        $summary['total_correct_answers'] = (int)($summary['total_correct_answers'] ?? 0);
        $summary['total_wrong_answers'] = (int)($summary['total_wrong_answers'] ?? 0);

        return $summary;
    }
}

if (!function_exists('activity_save_user_summary')) {
    function activity_save_user_summary(string $uid, array $summary): void
    {
        activity_write_json(activity_summary_path($uid), $summary);
    }
}

if (!function_exists('activity_get_user_attempts')) {
    function activity_get_user_attempts(string $uid, int $limit = 50): array
    {
        $rows = activity_read_json(activity_attempts_path($uid), []);
        if (!is_array($rows)) {
            return [];
        }

        $rows = array_values(array_filter($rows, 'is_array'));

        usort($rows, static function(array $a, array $b): int {
            $ta = strtotime((string)($a['started_at'] ?? '')) ?: 0;
            $tb = strtotime((string)($b['started_at'] ?? '')) ?: 0;
            return $tb <=> $ta;
        });

        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        return $rows;
    }
}

if (!function_exists('activity_save_user_attempts')) {
    function activity_save_user_attempts(string $uid, array $attempts): void
    {
        activity_write_json(activity_attempts_path($uid), array_values($attempts));
    }
}

if (!function_exists('activity_mark_login')) {
    function activity_mark_login(string $uid, ?string $page = null): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $summary = activity_get_user_summary($uid);
        $summary['last_login_at'] = activity_now_iso();
        $summary['last_seen_at'] = activity_now_iso();
        $summary['last_page'] = (string)($page ?? '');

        activity_save_user_summary($uid, $summary);

        $_SESSION['activity_current_user_id'] = $uid;
        $_SESSION['activity_last_ping_ts'] = time();
    }
}

if (!function_exists('activity_ping')) {
    function activity_ping(string $uid, string $page = ''): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $summary = activity_get_user_summary($uid);

        $now = time();
        $lastTs = (int)($_SESSION['activity_last_ping_ts'] ?? 0);
        $currentUid = (string)($_SESSION['activity_current_user_id'] ?? '');

        if ($currentUid !== $uid) {
            $_SESSION['activity_current_user_id'] = $uid;
            $_SESSION['activity_last_ping_ts'] = $now;
            $lastTs = $now;
        }

        $delta = 0;
        if ($lastTs > 0) {
            $delta = $now - $lastTs;
            if ($delta < 0) $delta = 0;
            if ($delta > 90) $delta = 30;
        }

        if ($delta > 0) {
            $summary['total_site_seconds'] = (int)$summary['total_site_seconds'] + $delta;
        }

        $summary['last_seen_at'] = activity_now_iso();
        $summary['last_page'] = $page;

        activity_save_user_summary($uid, $summary);

        $_SESSION['activity_last_ping_ts'] = $now;
    }
}

if (!function_exists('activity_start_attempt')) {
    function activity_start_attempt(string $uid, array $meta = []): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $attemptId = 'att_' . bin2hex(random_bytes(6));
        $nowTs = time();

        $attempt = [
            'id' => $attemptId,
            'test_id' => (string)($meta['test_id'] ?? ''),
            'test_title' => (string)($meta['test_title'] ?? ''),
            'test_mode' => (string)($meta['test_mode'] ?? ''),
            'started_at' => gmdate('c', $nowTs),
            'started_ts' => $nowTs,
            'finished_at' => null,
            'duration_seconds' => 0,
            'total_questions' => (int)($meta['total_questions'] ?? 0),
            'correct_answers' => 0,
            'wrong_answers' => 0,
            'status' => 'started',
        ];

        $attempts = activity_get_user_attempts($uid, 1000);
        array_unshift($attempts, $attempt);
        activity_save_user_attempts($uid, $attempts);

        $summary = activity_get_user_summary($uid);
        $summary['tests_started'] = (int)$summary['tests_started'] + 1;
        activity_save_user_summary($uid, $summary);

        $_SESSION['activity_attempt_id'] = $attemptId;

        return $attemptId;
    }
}

if (!function_exists('activity_mark_answer')) {
    function activity_mark_answer(string $uid, string $attemptId, bool $isCorrect): void
    {
        $attempts = activity_get_user_attempts($uid, 1000);
        $changed = false;

        foreach ($attempts as &$attempt) {
            if ((string)($attempt['id'] ?? '') !== $attemptId) {
                continue;
            }

            if ((string)($attempt['status'] ?? '') === 'finished') {
                break;
            }

            if ($isCorrect) {
                $attempt['correct_answers'] = (int)($attempt['correct_answers'] ?? 0) + 1;
            } else {
                $attempt['wrong_answers'] = (int)($attempt['wrong_answers'] ?? 0) + 1;
            }

            $changed = true;
            break;
        }
        unset($attempt);

        if ($changed) {
            activity_save_user_attempts($uid, $attempts);
        }
    }
}

if (!function_exists('activity_finish_attempt')) {
    function activity_finish_attempt(string $uid, string $attemptId): void
    {
        $attempts = activity_get_user_attempts($uid, 1000);
        $changed = false;
        $finishedAttempt = null;

        foreach ($attempts as &$attempt) {
            if ((string)($attempt['id'] ?? '') !== $attemptId) {
                continue;
            }

            if ((string)($attempt['status'] ?? '') === 'finished') {
                $finishedAttempt = $attempt;
                break;
            }

            $nowTs = time();
            $startedTs = (int)($attempt['started_ts'] ?? $nowTs);

            $attempt['finished_at'] = gmdate('c', $nowTs);
            $attempt['duration_seconds'] = max(0, $nowTs - $startedTs);
            $attempt['status'] = 'finished';

            $finishedAttempt = $attempt;
            $changed = true;
            break;
        }
        unset($attempt);

        if ($changed) {
            activity_save_user_attempts($uid, $attempts);

            $summary = activity_get_user_summary($uid);
            $summary['tests_finished'] = (int)$summary['tests_finished'] + 1;
            $summary['total_correct_answers'] = (int)$summary['total_correct_answers'] + (int)($finishedAttempt['correct_answers'] ?? 0);
            $summary['total_wrong_answers'] = (int)$summary['total_wrong_answers'] + (int)($finishedAttempt['wrong_answers'] ?? 0);
            $summary['last_test_at'] = activity_now_iso();

            activity_save_user_summary($uid, $summary);
        }
    }
}