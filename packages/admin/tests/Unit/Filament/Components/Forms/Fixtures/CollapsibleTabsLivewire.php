<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Components\Forms\Fixtures;

use Capell\Admin\Filament\Components\Forms\CollapsibleTabs;
use Capell\Admin\Tests\Fixtures\Livewire;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Override;

final class CollapsibleTabsLivewire extends Livewire
{
    public function mount(): void
    {
        $this->form->fill(['title' => 'Studio news', 'summary' => 'Public summary']);
    }

    #[Override]
    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            CollapsibleTabs::make('Editorial')->tabs([
                Tab::make('Content')->schema([TextInput::make('title')]),
                Tab::make('Metadata')->schema([TextInput::make('summary')]),
            ]),
        ]);
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
