<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Override;

class IconPicker extends \Guava\IconPicker\Forms\Components\IconPicker
{
    #[Override]
    protected function setUp(): void
    {
        $this->label(__('capell-admin::form.icon'));
    }
}
