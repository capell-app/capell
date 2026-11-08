<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Rollback;

use Spatie\LaravelData\Data;

/**
 * What a single page version changed compared with the version before it.
 * Presentation-neutral: the admin renders it with the same diff presenter
 * as a rollback preview.
 */
final class VersionComparisonData extends Data
{
    /**
     * @param  list<RollbackFieldChangeData>  $fields
     */
    public function __construct(
        public readonly int $version,
        public readonly ?int $previousVersion,
        public readonly array $fields,
    ) {}

    public function hasChanges(): bool
    {
        return array_any($this->fields, fn (RollbackFieldChangeData $field): bool => $field->changeType !== RollbackChangeType::Unchanged);
    }
}
