<?php

declare(strict_types=1);

namespace EduCloud\Core;

/**
 * Structured JSON-lines logger: {storage}/logs/app-YYYY-MM-DD.log
 * Context keys that look sensitive are redacted recursively; values are never logged for them.
 */
final class Logger
{
    private const SENSITIVE = '/(pass(word)?|secret|token|authorization|cookie|jwt|api[_-]?key|smtp_pass|csrf|session)/i';
    private const MAX_STRING = 2000;

    private string $requestId = '';

    public function __construct(private readonly string $directory)
    {
    }

    public function setRequestId(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    /** @param array<string, mixed> $context */
    public function info(string $event, array $context = []): void
    {
        $this->write('info', $event, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $event, array $context = []): void
    {
        $this->write('warning', $event, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $event, array $context = []): void
    {
        $this->write('error', $event, $context);
    }

    /**
     * @param array<array-key, mixed> $context nested arrays may have integer keys
     * @return array<array-key, mixed>
     */
    public static function redact(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE, $key) === 1) {
                $out[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $out[$key] = self::redact($value);
            } elseif (is_string($value)) {
                $out[$key] = mb_strlen($value) > self::MAX_STRING ? mb_substr($value, 0, self::MAX_STRING) . '…' : $value;
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            } else {
                $out[$key] = get_debug_type($value);
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $event, array $context): void
    {
        $record = [
            'ts' => gmdate('Y-m-d\TH:i:s') . sprintf('.%03dZ', (int) (fmod(microtime(true), 1) * 1000)),
            'level' => $level,
            'event' => $event,
            'request_id' => $this->requestId !== '' ? $this->requestId : null,
            'context' => self::redact($context),
        ];
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            error_log('EduCloud logger: cannot create log directory');
            return;
        }
        @file_put_contents($this->directory . '/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
