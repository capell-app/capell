<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions;

use Filament\Actions\Action;
use Override;

class HintEditAction extends Action
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->color('gray')
            ->hiddenLabel()
            ->icon('heroicon-m-pencil-square')
            ->iconButton()
            ->openUrlInNewTab()
            ->size('sm')
            ->tooltip(__('capell-admin::button.edit'));
    }
}
