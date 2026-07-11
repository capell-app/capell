<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Activity;

use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

final class ActivityChangeSetData extends Data
{
    /**
     * @param  list<ActivityChangedFieldData>  $fields
     */
    public function __construct(
        public readonly string $summary,
        public readonly ?ActivityChangedResourceData $resource,
        public readonly array $fields,
        public readonly string $actorLabel,
        public readonly ?string $event,
        public readonly ?CarbonInterface $occurredAt,
        public readonly int|string|null $workspaceId,
        public readonly ?string $emptyMessage,
    ) {}
}
