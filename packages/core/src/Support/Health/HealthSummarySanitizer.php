<?php

declare(strict_types=1);

namespace Capell\Core\Support\Health;

final class HealthSummarySanitizer
{
    public function sanitize(string $value): string
    {
        $value = preg_replace('/\b(password|token|secret|authorization)\s*[=:]\s*[^\s,;]+/i', '$1=[redacted]', $value) ?? '';
        $value = preg_replace('#(?:[A-Za-z]:)?[/\\\\](?:[^\s:/\\\\]+[/\\\\])+[^\s:]+#', '[path]', $value) ?? '';
        $value = preg_replace('/\b[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}\b/', '[email]', $value) ?? '';

        return mb_substr(trim(preg_replace('/\s+/', ' ', $value) ?? ''), 0, 500);
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $metrics
     * @return array<string, bool|float|int|string|null>
     */
    public function sanitizeMetrics(array $metrics): array
    {
        return array_map(fn (bool|float|int|string|null $value): bool|float|int|string|null => is_string($value) ? $this->sanitize($value) : $value, $metrics);
    }
}
