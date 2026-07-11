<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum BlueprintCreationModeEnum: string implements HasLabel
{
    use HasEnumOptions;

    case Basic = 'basic';

    case Custom = 'custom';

    public function getLabel(): string
    {
        return match ($this) {
            self::Basic => __('capell-admin::generic.basic'),
            self::Custom => __('capell-admin::generic.custom'),
        };
    }
}
