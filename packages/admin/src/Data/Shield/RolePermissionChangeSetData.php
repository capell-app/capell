<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Shield;

use Spatie\LaravelData\Data;

final class RolePermissionChangeSetData extends Data
{
    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @param  list<string>  $added
     * @param  list<string>  $removed
     * @param  list<string>  $unchanged
     */
    public function __construct(
        public readonly array $before,
        public readonly array $after,
        public readonly array $added,
        public readonly array $removed,
        public readonly array $unchanged,
    ) {}

    public function hasChanges(): bool
    {
        return $this->added !== [] || $this->removed !== [];
    }

    public function summary(): string
    {
        return sprintf('%d added, %d removed', count($this->added), count($this->removed));
    }
}
