<?php

declare(strict_types=1);

namespace Capell\Core\Support\Reporting;

final class SignalRedactor
{
    private const string REDACTED = '[redacted]';

    private const string SENSITIVE_KEY_FRAGMENT = 'password|passwd|pwd|token|secret|key|auth|cookie|session|credential|signature|licen[cs]e|email|phone|mobile|address|name|user|customer|contact|person|birth|ssn|card|iban|latitude|longitude';

    private const string SENSITIVE_KEY_PATTERN = '~(?:' . self::SENSITIVE_KEY_FRAGMENT . '|^ip$)~';

    private const string SENSITIVE_LABEL_KEY_PATTERN = '(?:ip|[a-z0-9_-]*(?:' . self::SENSITIVE_KEY_FRAGMENT . ')[a-z0-9_-]*)';

    private const string LABELLED_VALUE_PATTERN = <<<'REGEX'
        ~(?<![a-z0-9_-])(?<key>%s)(?:\\?["'])?\s*[=:]\s*(?:[\[{].*|"(?:\\.|[^"\\])*(?:"|\\?\z)|'(?:\\.|[^'\\])*(?:'|\\?\z)|[^\r\n,;]*)~is
        REGEX;

    private const string ENCODED_DELIMITER_PATTERN = <<<'REGEX'
        ~\+|%[a-f0-9]{2}|\\u[a-f0-9]{4}|[=:]\s*\\+["']~i
        REGEX;

    public function text(string $value): string
    {
        $value = mb_convert_encoding(substr($value, 0, 8192), 'UTF-8', 'UTF-8');

        // Encoded delimiters can change where a quoted credential ends. Inspect them
        // before removing assignments so a partially matched value cannot lose its label.
        if (preg_match(self::ENCODED_DELIMITER_PATTERN, $value) === 1) {
            $value = $this->redactEncodedText($value);
        }

        $value = $this->redactEncodedText($this->redactPlainText($value));

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

    private function redactPlainText(string $value): string
    {
        // Structured credentials consume the remaining text: a partial or embedded
        // JSON fragment cannot safely establish where its sensitive descendants end.
        $value = preg_replace([
            '~\b[a-z][a-z0-9+.-]*://[^\s<>]+~i',
            '/\b(?:Bearer|Basic)\s+[^\s,;]+/i',
            '/\b(?:(?:set-)?cookie|(?:proxy-)?authorization)[\t ]*:[\t ]*[^\r\n]*(?:(?:\r\n|[\r\n])[\t ]+[^\r\n]*)*/i',
        ], self::REDACTED, $value) ?? self::REDACTED;
        $value = preg_replace_callback(sprintf(self::LABELLED_VALUE_PATTERN, self::SENSITIVE_LABEL_KEY_PATTERN), fn (array $match): string => $this->isSensitiveKey($match['key']) ? self::REDACTED : $match[0], $value) ?? self::REDACTED;

        return preg_replace([
            '/\b(?:gh[pousr]_\w{20,}|github_pat_\w{20,}|eyJ[\w-]+\.[\w-]+\.[\w-]+)\b/',
            '/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/',
            '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
            '/\b(?:[a-f0-9]{0,4}:){2,}[a-f0-9:]*\b/i',
            '/(?<!\w)\+?\d[\d ().-]{7,}\d(?!\w)/',
            '~(?:[A-Za-z]:)?[/\\\\](?:[^\s:/\\\\"\']+[/\\\\])+[^\s:"\']+~',
        ], self::REDACTED, $value) ?? self::REDACTED;
    }

    private function redactEncodedText(string $value): string
    {
        $decoded = $this->decodeText($value);

        // Withhold the original encoded field when any decoded content is sensitive;
        // copying replacement offsets between encodings can retain credential fragments.
        return $decoded === null || ($decoded !== $value && $this->redactPlainText($decoded) !== $decoded)
            ? self::REDACTED
            : $value;
    }

    private function decodeText(string $value): ?string
    {
        for ($depth = 0; $depth < 8; $depth++) {
            $decoded = preg_replace_callback(
                '~\\\\(?:u[a-f0-9]{4}|["\\\\/bfnrt])~i',
                static function (array $match): string {
                    $character = json_decode('"' . $match[0] . '"');

                    return is_string($character) ? $character : self::REDACTED;
                },
                urldecode($value),
            );

            if ($decoded === null || $decoded === $value) {
                return $decoded;
            }

            $value = $decoded;
        }

        return null;
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
            $decodedKey = $this->decodeText((string) $key);
            if ($decodedKey === null || $this->isSensitiveKey($decodedKey)) {
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

    private function isSensitiveKey(string $key): bool
    {
        $normalised = strtolower(preg_replace('/[^a-z0-9]/i', '', $key) ?? '');

        return preg_match(self::SENSITIVE_KEY_PATTERN, $normalised) === 1;
    }
}
