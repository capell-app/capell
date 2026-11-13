<?php

declare(strict_types=1);

namespace Capell\Core\Data\Capabilities;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class PublishingWorkspaceTransitionData extends Data
{
    public function __construct(
        public int $workspaceId,
        public string $previousState,
        public string $newState,
        public string $transition,
        public CarbonImmutable $transitionedAt,
        public ?int $siteId = null,
        public int|string|null $actorId = null,
        public ?string $notes = null,
    ) {}
}
