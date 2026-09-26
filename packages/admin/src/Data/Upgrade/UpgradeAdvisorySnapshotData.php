<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Upgrade;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class UpgradeAdvisorySnapshotData extends Data
{
    /**
     * @param  array<int, array<string, mixed>>  $updates
     * @param  array<int, array<string, mixed>>  $advisories
     */
    public function __construct(
        public readonly array $updates,
        public readonly array $advisories,
        public readonly ?CarbonImmutable $checked_at,
        public readonly ?string $capell_version,
    ) {}
}
