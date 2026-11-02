<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

use Capell\Core\Support\Reporting\SignalRedactor;

final class SecurityOutputSanitiser
{
    public function text(string $value, int $limit): string
    {
        return mb_substr((new SignalRedactor)->text($value), 0, $limit);
    }

    /** @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    public function context(array $value): array
    {
        $safe = (new SignalRedactor)->context($value);

        return strlen(json_encode($safe, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)) <= 8192 ? $safe : ['truncated' => true];
    }
}
