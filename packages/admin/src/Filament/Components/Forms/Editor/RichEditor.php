<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Editor;

use Filament\Forms;
use Override;

class RichEditor extends Forms\Components\RichEditor
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.content'))
            ->textColors([]);
    }
}
