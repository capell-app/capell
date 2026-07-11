<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Security;

final class JsonLdScriptSanitizer
{
    public static function sanitize(string $jsonLd): string
    {
        return (string) preg_replace('/<\/script/i', '<\/script', $jsonLd);
    }
}
