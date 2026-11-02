<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Regex redaction is defence in depth, not a complete secret boundary. */
final class RedactMarketplaceDiagnosticContextAction
{
    use AsFake;
    use AsObject;

    private const string REDACTED = '[redacted]';

    /** @var list<string> */
    private const array SECRET_KEY_FRAGMENTS = [
        'auth',
        'authorization',
        'bearer',
        'key',
        'licence',
        'license',
        'password',
        'secret',
        'signature',
        'token',
    ];

    /** @param array<string, mixed> $context */
    public function handle(array $context): array
    {
        return $this->redactArray($context);
    }

    /** @param array<string, mixed> $context */
    private function redactArray(array $context): array
    {
        $redacted = [];

        foreach ($context as $key => $value) {
            $redacted[$key] = $this->shouldRedactKey((string) $key)
                ? self::REDACTED
                : $this->redactValue($value);
        }

        return $redacted;
    }

    private function redactValue(mixed $value): mixed
    {
        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return $this->redactArray($value);
        }

        if (! is_string($value)) {
            return $value;
        }

        $value = preg_replace_callback(
            '/"(?:github-oauth|http-basic|bearer|gitlab-token|bitbucket-oauth)"\s*:\s*\{(?:[^{}]|\{[^{}]*\})*\}/i',
            static fn (array $matches): string => preg_replace(
                '/("(?:[^"\\\\]|\\\\.)*"\s*:\s*")((?:[^"\\\\]|\\\\.)*)(")/',
                '$1' . self::REDACTED . '$3',
                $matches[0],
            ) ?? self::REDACTED,
            $value,
        ) ?? self::REDACTED;

        $value = preg_replace([
            '~([a-z][a-z0-9+.-]*://)[^\s/@]+@~i',
            '/((?:bearer|basic|digest|token)\s+)[^\s,"\']+/i',
            '/("[^"\\\\]*(?:auth|oauth|secret|token|key|password|licen[cs]e)[^"\\\\]*"\s*:\s*")((?:[^"\\\\]|\\\\.)*)(")/i',
        ], [
            '$1' . self::REDACTED . '@',
            '$1' . self::REDACTED,
            '$1' . self::REDACTED . '$3',
        ], $value) ?? self::REDACTED;

        return preg_replace_callback(
            '/([a-z0-9_%.-]+)(\s*[=:]\s*)(?!bearer\s|basic\s|digest\s|token\s)([^\s,"\'&]+)/i',
            fn (array $matches): string => $this->shouldRedactKey(rawurldecode($matches[1]))
                ? $matches[1] . $matches[2] . self::REDACTED
                : $matches[0],
            $value,
        ) ?? self::REDACTED;
    }

    private function shouldRedactKey(string $key): bool
    {
        $key = Str::lower($key);

        return array_any(self::SECRET_KEY_FRAGMENTS, fn (string $fragment): bool => str_contains($key, $fragment));
    }
}
