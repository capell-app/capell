<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

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
            ->options([
                'title' => __('capell-admin::form.title'),
                'content' => __('capell-admin::form.content'),
            ])
            ->columns()
            ->gridDirection(GridDirection::Row);
    }

    public static function getDefaultName(): ?string
    {
        return 'required_fields';
    }
}
