<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use Exception;

class UrlMissingSiteDomainException extends Exception
{
    public static function forPageUrl(int|string|null $pageId, int|string|null $siteId, int|string|null $languageId): self
    {
        return new self(sprintf(
            'Site domain not found for page ID %d, site ID %d, and language ID %d',
            $pageId,
            $siteId,
            $languageId,
        ));
    }
}
