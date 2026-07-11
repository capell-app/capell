<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions;

use Override;

class DeleteAction extends \Filament\Actions\DeleteAction
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->icon('heroicon-m-trash');
    }
}
