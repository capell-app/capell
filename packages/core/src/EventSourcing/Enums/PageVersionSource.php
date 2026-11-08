<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Enums;

/**
 * Where a page version came from. Every version of a page, whoever or whatever
 * made it, is one row in the single versions store (page_revisions); this
 * records its provenance so the timeline can say "Seed or import" or
 * "Published from a workspace" instead of an anonymous "System".
 *
 * Rows recorded before provenance existed have no stored source; they resolve
 * through {@see self::forStored()} to Edit or Rollback.
 */
enum PageVersionSource: string
{
    case Edit = 'edit';
    case Rollback = 'rollback';
    case Import = 'import';
    case Publish = 'publish';

    public static function forStored(?string $stored, bool $isRollback): self
    {
        $source = $stored === null ? null : self::tryFrom($stored);

        return $source ?? ($isRollback ? self::Rollback : self::Edit);
    }
}
