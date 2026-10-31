<?php

namespace App\Libraries;

use CodeIgniter\Database\Query;

/**
 * Optional troubleshooting log, for client PCs that cannot be reached directly.
 * Turned on with `diagnostics.enabled = true` in .env and off by default.
 *
 * Writes one JSON object per line to writable/logs/diagnostics-YYYY-MM-DD.log: requests, responses,
 * SQL queries, errors and what the user did in the browser. All lines of one request share a `rid`.
 * Secrets are masked. Logging must never break the application, so it swallows its own errors.
 */
class Diagnostics
{
    public const MAX_TEXT = 4000;
    public const MAX_BODY = 65536;
    public const MAX_FILE_BYTES = 104857600; // 100 MB a day
    public const KEEP_DAYS = 7;

    private const SECRET_KEYS = '/pass|pwd|secret|token|csrf|authorization|cookie|api_?key|private_?key|encryption/i';
    private const SECRET_SQL = '/pass|pwd|secret|token|api_?key|private_?key/i';

    private static ?string $requestId = null;
    private static bool $muted = false;
    private static int $queryCount = 0;
    private static float $queryMs = 0.0;

    public static function enabled(): bool
    {
        return filter_var(env('diagnostics.enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function requestId(): string
    {
        return self::$requestId ??= bin2hex(random_bytes(4));
    }

    /** Stops logging for this request (the log's own endpoints would otherwise fill the log). */
    public static function mute(bool $muted = true): void
    {
        self::$muted = $muted;
    }

    public static function queryStats(): array
    {
        return ['count' => self::$queryCount, 'ms' => round(self::$queryMs, 1)];
    }

    public static function write(string $type, array $data = []): void
    {
        if (self::$muted || !self::enabled()) {
            return;
        }
        try {
            $file = self::file(date('Y-m-d'));
            if (!is_file($file)) {
                self::prune();
            } elseif (filesize($file) >= self::MAX_FILE_BYTES) {
                return;
            }
            $line = ['time' => date('Y-m-d H:i:s'), 'rid' => self::requestId(), 'type' => $type]
                + self::sessionInfo() + self::mask($data);
            $json = json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if (is_string($json)) {
                @file_put_contents($file, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
        } catch (\Throwable) {
            // Never let the log break the screen being diagnosed.
        }
    }

    /** DBQuery event listener. */
    public static function query(Query $query): void
    {
        if (self::$muted || !self::enabled()) {
            return;
        }
        try {
            $ms = (float) $query->getDuration(5) * 1000;
            self::$queryCount++;
            self::$queryMs += $ms;
            $data = ['sql' => self::cut(self::maskSql((string) $query->getQuery())), 'ms' => round($ms, 2)];
            if ($query->hasError()) {
                $data['error'] = $query->getErrorCode() . ': ' . self::maskSql((string) $query->getErrorMessage());
            }
            self::write('query', $data);
        } catch (\Throwable) {
        }
    }

    /** Replaces the value of any secret-looking key, at any depth. */
    public static function mask(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEYS, $key)) {
                $data[$key] = '***';
            } elseif (is_array($value)) {
                $data[$key] = self::mask($value);
            }
        }
        return $data;
    }

    /** A query that touches a secret column keeps its shape but loses all of its quoted values. */
    public static function maskSql(string $sql): string
    {
        if (!preg_match(self::SECRET_SQL, $sql)) {
            return $sql;
        }
        return (string) preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", "'***'", $sql);
    }

    public static function cut(string $text, int $max = self::MAX_TEXT): string
    {
        return strlen($text) > $max ? substr($text, 0, $max) . '...[' . strlen($text) . ' bytes]' : $text;
    }

    public static function file(string $date): string
    {
        return WRITEPATH . 'logs' . DIRECTORY_SEPARATOR . 'diagnostics-' . $date . '.log';
    }

    private static function sessionInfo(): array
    {
        $session = $_SESSION ?? [];
        return ['user' => $session['user_id'] ?? null, 'role' => $session['role'] ?? null, 'location' => $session['location_id'] ?? null];
    }

    private static function prune(): void
    {
        $oldest = time() - self::KEEP_DAYS * 86400;
        foreach (glob(WRITEPATH . 'logs' . DIRECTORY_SEPARATOR . 'diagnostics-*.log') ?: [] as $old) {
            if ((int) @filemtime($old) < $oldest) {
                @unlink($old);
            }
        }
    }
}
