<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Tables\Actions;

use Livewire\Component;
use Override;

class CreateAction extends \Filament\Actions\CreateAction
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->after(function (Component $livewire): void {
            $livewire->dispatch('refresh');
        });
    }
}
