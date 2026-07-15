<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Publishing;

use Capell\Core\Data\Publishing\PublicationTransitionResultData;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class PublishReadinessData extends Data
{
    /**
     * @param  list<string>  $blockingCheckIds
     * @param  list<string>  $allowedTransitions
     */
    public function __construct(
        public readonly PublishVisibilityStateEnum $currentState,
        public readonly array $blockingCheckIds,
        public readonly ?CarbonImmutable $scheduledPublishAt,
        public readonly ?CarbonImmutable $scheduledUnpublishAt,
        public readonly bool $publicEligible,
        public readonly array $allowedTransitions,
        public readonly ?PublicationTransitionResultData $preview = null,
    ) {}
}
