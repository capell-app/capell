<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Support\Filament\Fixtures;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Component;

final class ArrayRecordTableLivewire extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function table(Table $table): Table
    {
        return $table
            ->records(static fn (): array => [
                'seo' => ['name' => 'Search tools', 'status' => 'Installed'],
                'forms' => ['name' => 'Form builder', 'status' => 'Available'],
            ])
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('status'),
            ]);
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div>{{ $this->table }}</div>
            BLADE;
    }
}
