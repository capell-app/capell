<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

final class RedactUpgradeRunContextAction
{
    use AsAction;

    private const string REDACTED = '[redacted]';

    /**
     * @var list<string>
     */
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

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function handle(array $context): array
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
            /** @var array<string, mixed> $nested */
            $nested = $value;

            return $this->handle($nested);
        }

        if (! is_string($value)) {
            return $value;
        }

        return preg_replace([
            '/\b((?:https?|git):\/\/)[^\s\/@:]+:[^\s\/@]+@/i',
            '/\b(?:gh[pousr]_\w{20,}|github_pat_\w{20,})\b/',
            '/(password|passwd|token|secret|api[_-]?key|authorization)(=|:)\s*[^ \n\r]+/i',
            '/Bearer\s+[A-Za-z0-9._~+\/=-]+/i',
            '/(COMPOSER_AUTH=)[^\s]+/i',
            '/("[^"]*(?:auth|oauth|secret|token|key|password|licen[cs]e)[^"]*"\s*:\s*")([^"]+)(")/i',
            '/("[^"]*(?:github-oauth|http-basic)[^"]*"\s*:\s*\{[^}]*:\s*")([^"]+)(")/i',
        ], [
            '$1' . self::REDACTED . '@',
            self::REDACTED,
            '$1$2 ' . self::REDACTED,
            'Bearer ' . self::REDACTED,
            '$1' . self::REDACTED,
            '$1' . self::REDACTED . '$3',
            '$1' . self::REDACTED . '$3',
        ], $value) ?? $value;
    }

    private function shouldRedactKey(string $key): bool
    {
        $key = Str::lower($key);

        return array_any(self::SECRET_KEY_FRAGMENTS, fn (string $fragment): bool => str_contains($key, $fragment));
    }
}
