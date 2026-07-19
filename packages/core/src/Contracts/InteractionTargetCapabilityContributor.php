<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

use Capell\Core\Enums\InteractionTargetType;

interface InteractionTargetCapabilityContributor
{
    public const string TAG = 'capell.interaction-target-capability-contributor';

    public function supports(InteractionTargetType $targetType): bool;
}
