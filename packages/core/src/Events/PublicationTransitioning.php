<?php

declare(strict_types=1);

namespace Capell\Core\Events;

use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Data\Publishing\PublicationTransitionResultData;

final readonly class PublicationTransitioning
{
    public function __construct(
        public string $transitionId,
        public PublicationTransitionRequestData $request,
        public PublicationTransitionResultData $result,
    ) {}
}
