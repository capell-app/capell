<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

final class RedactMarketplaceDiagnosticContextAction
{
    use AsAction;

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

        return preg_replace([
            '/(bearer\s+)[^\s,"\']+/i',
            '/([a-z_]*(?:auth|oauth|secret|token|key|password|licen[cs]e)[a-z_]*\s*[=:]\s*)([^\s,"\']+)/i',
            '/("[^"]*(?:auth|oauth|secret|token|key|password|licen[cs]e)[^"]*"\s*:\s*")([^"]+)(")/i',
            '/("[^"]*(?:github-oauth|http-basic)[^"]*"\s*:\s*\{[^}]*:\s*")([^"]+)(")/i',
        ], [
            '$1' . self::REDACTED,
            '$1' . self::REDACTED,
            '$1' . self::REDACTED . '$3',
            '$1' . self::REDACTED . '$3',
        ], $value) ?? self::REDACTED;
    }

    private function shouldRedactKey(string $key): bool
    {
        $key = Str::lower($key);

        return array_any(self::SECRET_KEY_FRAGMENTS, fn (string $fragment): bool => str_contains($key, $fragment));
    }
}
