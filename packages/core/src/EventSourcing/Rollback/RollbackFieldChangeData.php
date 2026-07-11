<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Rollback;

use Spatie\LaravelData\Data;

/**
 * A neutral, package-independent diff entry for one top-level section of an
 * aggregate's serialised state (e.g. attributes, translations, pageUrls).
 *
 * Core deliberately does not reference the admin activity-diff DTOs; the admin
 * layer maps these neutral entries onto its own presenter so the existing diff
 * renderer is reused without core depending on admin.
 */
final class RollbackFieldChangeData extends Data
{
    public function __construct(
        public readonly string $path,
        public readonly string $label,
        public readonly mixed $before,
        public readonly mixed $after,
        public readonly RollbackChangeType $changeType,
    ) {}
}
