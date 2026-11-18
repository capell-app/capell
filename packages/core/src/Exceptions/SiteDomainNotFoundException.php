<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use Exception;

class SiteDomainNotFoundException extends Exception
{
    public static function forLanguage(int|string $languageId): self
    {
        return new self('Site domain not found for language: ' . $languageId);
    }

    public static function noSitesConfigured(): self
    {
        return new self('No sites are configured.');
    }
}
