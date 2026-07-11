<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Capell\Core\Models\Blueprint;
use Capell\Core\Support\Creator\BlueprintCreator;

enum PageTypeEnum: string
{
    case Default = 'default';
    case Home = 'home';
    case Maintenance = 'maintenance';
    case NotFound = 'error';
    case System = 'system';

    public function createPageType(): Blueprint
    {
        $typeCreator = resolve(BlueprintCreator::class);

        return match ($this) {
            PageTypeEnum::Default => $typeCreator->defaultPageType(),
            PageTypeEnum::Maintenance => $typeCreator->maintenancePageType(),
            PageTypeEnum::NotFound => $typeCreator->notFoundPageType(),
            PageTypeEnum::Home => $typeCreator->homePageType(),
            PageTypeEnum::System => $typeCreator->systemPageType(),
        };
    }

    public function defaultLayoutEnum(): LayoutEnum
    {
        return match ($this) {
            self::Maintenance, self::NotFound, self::System => LayoutEnum::System,
            default => LayoutEnum::Default,
        };
    }
}
