<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Auth;

use Illuminate\Support\Str;

class EmailMasker
{
    public function mask(string $email): string
    {
        [$localPart, $domain] = explode('@', $email, 2);
        $domainParts = explode('.', $domain);
        $topLevelDomain = array_pop($domainParts);
        $domainName = implode('.', $domainParts);

        return sprintf(
            '%s@%s.%s',
            $this->maskSegment($localPart),
            $this->maskSegment($domainName),
            $topLevelDomain,
        );
    }

    private function maskSegment(string $value): string
    {
        $length = Str::length($value);

        if ($length <= 1) {
            return '*';
        }

        if ($length === 2) {
            return Str::substr($value, 0, 1) . '*';
        }

        return Str::substr($value, 0, 1)
            . str_repeat('*', max(1, $length - 2))
            . Str::substr($value, -1);
    }
}
