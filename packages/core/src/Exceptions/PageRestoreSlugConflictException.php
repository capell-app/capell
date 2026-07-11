<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use Capell\Core\Models\Page;
use RuntimeException;

/**
 * Thrown by PageObserver::restored() when restoring a soft-deleted page
 * would collide with a slug now owned by a live page. The admin layer
 * catches this and prompts the editor to pick a new slug.
 */
final class PageRestoreSlugConflictException extends RuntimeException
{
    /**
     * @param  array<string, int>  $collisions  map of slug → live-page-id
     */
    public function __construct(
        public readonly Page $page,
        public readonly array $collisions,
    ) {
        parent::__construct(sprintf(
            'Restoring page #%d would collide on %d slug(s): %s',
            $page->getKey(),
            count($collisions),
            implode(', ', array_keys($collisions)),
        ));
    }
}
