<?php

declare(strict_types=1);

namespace Capell\Core\Support\Reporting;

final class SignalRedactor
{
    private const string REDACTED = '[redacted]';

    public function text(string $value): string
    {
        $value = mb_convert_encoding(substr($value, 0, 8192), 'UTF-8', 'UTF-8');
        $value = preg_replace([
            '~\b[a-z][a-z0-9+.-]*://[^\s<>]+~i',
            '/\b(?:Bearer|Basic)\s+[^\s,;]+/i',
            '/\b[a-z0-9_-]*(?:password|passwd|pwd|token|secret|api[_-]?key|access[_-]?key|authorization|cookie|email|phone|address)[a-z0-9_-]*["\']?\s*[=:]\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;]+)/i',
            '/\b(?:gh[pousr]_\w{20,}|github_pat_\w{20,}|eyJ[\w-]+\.[\w-]+\.[\w-]+)\b/',
            '/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/',
            '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
            '/\b(?:[a-f0-9]{0,4}:){2,}[a-f0-9:]*\b/i',
            '/(?<!\w)\+?\d[\d ().-]{7,}\d(?!\w)/',
            '~(?:[A-Za-z]:)?[/\\\\](?:[^\s:/\\\\]+[/\\\\])+[^\s:]+~',
        ], self::REDACTED, $value) ?? self::REDACTED;

        return mb_substr(trim(preg_replace('/[\x00-\x20\x7f]+/', ' ', $value) ?? self::REDACTED), 0, 2048);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function context(array $context): array
    {
        $remaining = 100;

        return $this->redactArray($context, 0, $remaining);
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $context
     * @return array<TKey|string, mixed>
     */
    private function redactArray(array $context, int $depth, int &$remaining): array
    {
        $redacted = [];

        foreach ($context as $key => $value) {
            if ($remaining-- <= 0) {
                $redacted['[truncated]'] = true;
                break;
            }

            $safeKey = is_string($key) && $this->text($key) !== $key ? '[redacted-key-' . $remaining . ']' : $key;
            $normalisedKey = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $key) ?? '');
            if (preg_match('/password|passwd|pwd|token|secret|key|auth|cookie|session|credential|signature|licen[cs]e|email|phone|mobile|address|name|user|customer|contact|person|birth|ssn|card|iban|^ip$|latitude|longitude/', $normalisedKey) === 1) {
                $redacted[$safeKey] = self::REDACTED;
            } elseif (is_array($value)) {
                $redacted[$safeKey] = $depth >= 6 ? '[truncated]' : $this->redactArray($value, $depth + 1, $remaining);
            } elseif (is_string($value)) {
                $redacted[$safeKey] = $this->text($value);
            } else {
                $redacted[$safeKey] = is_int($value) || is_bool($value) || $value === null || (is_float($value) && is_finite($value))
                    ? $value
                    : self::REDACTED;
            }
        }

        return $redacted;
    }
}
