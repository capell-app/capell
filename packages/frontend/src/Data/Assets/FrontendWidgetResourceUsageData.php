<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use Capell\Core\Data\Presentation\PresentationSettingsData;
use Capell\Core\Enums\PresentationLoadingStrategy;
use Spatie\LaravelData\Data;

final class FrontendWidgetResourceUsageData extends Data
{
    public function __construct(
        public readonly string $widgetKey,
        public readonly string $resourceGroup,
        public readonly string $publicId,
        public readonly PresentationSettingsData $presentation,
        public readonly ?PresentationLoadingStrategy $loadingStrategy = null,
    ) {}
}
