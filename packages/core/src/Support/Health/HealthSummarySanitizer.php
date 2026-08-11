<?php

declare(strict_types=1);

namespace Capell\Core\Support\Health;

final class HealthSummarySanitizer
{
    public function sanitize(string $value): string
    {
        $value = preg_replace('#\b([a-z][a-z0-9+.-]*://)[^@\s/]+@#i', '$1[redacted]@', $value) ?? '';
        $value = preg_replace('/\bBearer\s+[^\s,;]+/i', 'Bearer [redacted]', $value) ?? '';
        $value = preg_replace('/\b(api[_-]?key|access[_-]?key|password|token|secret|authorization)\s*[=:]\s*[^\s,;]+/i', '$1=[redacted]', $value) ?? '';
        $value = preg_replace('/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/', '[jwt]', $value) ?? '';
        $value = preg_replace('#(?:[A-Za-z]:)?[/\\\\](?:[^\s:/\\\\]+[/\\\\])+[^\s:]+#', '[path]', $value) ?? '';
        $value = preg_replace('/\b[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}\b/', '[email]', $value) ?? '';

        return mb_substr(trim(preg_replace('/\s+/', ' ', $value) ?? ''), 0, 500);
    }
}
