<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Components\Forms\Fixtures;

use Capell\Admin\Filament\Components\Forms\IconPicker;
use Capell\Admin\Tests\Fixtures\Livewire;
use Filament\Schemas\Schema;
use Override;

final class IconPickerLivewire extends Livewire
{
    public function mount(): void
    {
        $this->form->fill(['icon' => 'heroicon-o-home']);
    }

    #[Override]
    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([IconPicker::make('icon')]);
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
