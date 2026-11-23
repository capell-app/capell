<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;

/**
 * Thrown when a page URL would belong to one site while pointing at a page of another.
 */
final class PageUrlSiteMismatchException extends RuntimeException
{
    public function __construct(
        public readonly int $urlSiteId,
        public readonly int $pageSiteId,
    ) {
        parent::__construct(sprintf(
            'Site ID mismatch. The PageUrl(site_id=%d) must match the Page(site_id=%d).',
            $urlSiteId,
            $pageSiteId,
        ));
    }
}
