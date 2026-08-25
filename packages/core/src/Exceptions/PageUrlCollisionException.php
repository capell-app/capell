<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;

/**
 * Thrown when saving an active page URL would duplicate a live route for the
 * same site and language.
 */
final class PageUrlCollisionException extends RuntimeException
{
    public function __construct(
        public readonly string $url,
        public readonly int $siteId,
        public readonly int $languageId,
    ) {
        parent::__construct(sprintf(
            'Page URL "%s" already exists for site ID %d and language ID %d.',
            $url,
            $siteId,
            $languageId,
        ));
    }
}
