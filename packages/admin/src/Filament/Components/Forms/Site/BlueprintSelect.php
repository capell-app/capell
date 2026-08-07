<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Site;

use Capell\Admin\Filament\Components\Forms\BlueprintSelect as BaseBlueprintSelect;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Override;

class BlueprintSelect extends BaseBlueprintSelect
{
    #[Override]
    protected function setUp(?string $label = null): void
    {
        parent::setUp($label);

        $this->subject(BlueprintSubjectEnum::Site->getKey());
    }
}
