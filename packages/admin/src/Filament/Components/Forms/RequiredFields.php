<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Admin\Enums\PageRequiredFieldEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Support\Enums\GridDirection;
use Override;

class RequiredFields extends CheckboxList
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.required_fields'))
            ->options(PageRequiredFieldEnum::options())
            ->columns()
            ->gridDirection(GridDirection::Row);
    }

    public static function getDefaultName(): ?string
    {
        return 'required_fields';
    }
}
