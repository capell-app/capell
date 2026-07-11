<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Core\Data\Interactions\InteractionTargetData;

interface WidgetInteractionLocatorResolver
{
    /** Resolve only an already-built locator. Implementations must not query. */
    public function resolve(InteractionTargetData $target): ?string;
}
