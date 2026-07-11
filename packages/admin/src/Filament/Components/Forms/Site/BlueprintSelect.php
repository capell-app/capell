<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Site;

use Capell\Admin\Filament\Components\Forms\BlueprintSelect as BaseBlueprintSelect;
use Capell\Core\Enums\BlueprintSubjectEnum;

class BlueprintSelect extends BaseBlueprintSelect
{
    protected null|BlueprintSubjectEnum|string $type = BlueprintSubjectEnum::Site;
}
