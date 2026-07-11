<?php

declare(strict_types=1);

namespace Capell\Core\Data\Interactions;

use Capell\Core\Enums\InteractionTargetType;
use Spatie\LaravelData\Data;

class InteractionTargetData extends Data
{
    /**
     * @param  array<string, mixed>  $widgetData
     * @param  array<string, mixed>  $presentationSettings
     */
    public function __construct(
        public InteractionTargetType $type,
        public ?string $widgetType = null,
        public array $widgetData = [],
        public ?string $fragmentReference = null,
        public ?string $url = null,
        public ?string $publicActionKey = null,
        public array $presentationSettings = [],
        public ?string $fallbackUrl = null,
    ) {}
}
