<?php

declare(strict_types=1);

use Capell\Admin\Support\Filament\ArrayRecordColumnState;
use Capell\Admin\Tests\Feature\Support\Filament\Fixtures\ArrayRecordTableLivewire;
use Capell\Core\Models\Site;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;

function modelOnlyArrayRecordColumn(): TextColumn
{
    return TextColumn::make('name')
        ->table(Table::make(new ArrayRecordTableLivewire))
        ->getStateUsing(static fn (Column $column, Model $record): string => 'Translated ' . $record->getAttribute('name'));
}

it('renders the cells of a table whose records are arrays', function (): void {
    Livewire::test(ArrayRecordTableLivewire::class)
        ->assertSuccessful()
        ->assertSeeText(['Search tools', 'Installed', 'Form builder', 'Available']);
});

it('hands Eloquent records to a column state callback that only accepts models', function (): void {
    $column = modelOnlyArrayRecordColumn();

    ArrayRecordColumnState::guard($column);

    expect($column->record(Site::factory()->makeOne(['name' => 'Stored name']))->getState())
        ->toBe('Translated Stored name');
});

it('reads array records from the column when the state callback only accepts models', function (): void {
    $column = modelOnlyArrayRecordColumn();

    ArrayRecordColumnState::guard($column);

    expect($column->record([ArrayRecord::getKeyName() => 'seo', 'name' => 'Search tools'])->getState())->toBe('Search tools');
});

it('leaves state callbacks that accept array records untouched', function (): void {
    $callback = static fn (Model|array $record): string => 'Own state';
    $column = TextColumn::make('name')
        ->table(Table::make(new ArrayRecordTableLivewire))
        ->getStateUsing($callback);

    ArrayRecordColumnState::guard($column);

    expect($column->getGetStateUsingCallback())->toBe($callback)
        ->and($column->record([ArrayRecord::getKeyName() => 'seo', 'name' => 'Search tools'])->getState())->toBe('Own state');
});
