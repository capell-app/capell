<?php

declare(strict_types=1);

namespace Capell\Core\Data\Interactions;

use Capell\Core\Enums\InteractionBehavior;
use Capell\Core\Enums\InteractionTriggerEvent;
use Spatie\LaravelData\Data;

final class InteractionTriggerData extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public ?string $icon,
        public string $style,
        public InteractionTriggerEvent $event,
        public InteractionBehavior $behavior,
        public InteractionTargetData $target,
        public ?string $analyticsKey = null,
        public ?string $ariaLabel = null,
        public ?string $modalSize = null,
        public bool $closeOnBackdrop = true,
    ) {}
}
