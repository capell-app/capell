<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Filament\Forms\Components\TextInput;
use Override;

class CallToActionText extends TextInput
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.link_text'))
            ->helperText(__('capell-admin::generic.link_text_info'));
    }
}
