<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Filament\Forms\Components\ToggleButtons;
use Override;

class StatusToggle extends ToggleButtons
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.status'))
            ->hiddenLabel()
            ->boolean(
                trueLabel: __('capell-admin::generic.active'),
                falseLabel: __('capell-admin::generic.inactive'),
            )
            ->default(true)
            ->grouped();
    }
}
