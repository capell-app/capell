<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Media\MediaResource;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Filament\Resources\Themes\ThemeResource;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

it('exposes stable welcome tour targets on core admin tables', function (string $resource, string $tourId): void {
    $livewire = Mockery::mock(HasTable::class);
    $livewire->shouldIgnoreMissing();
    $livewire->shouldReceive('makeFilamentTranslatableContentDriver')->andReturn(null)->byDefault();
    $livewire->shouldReceive('getTableFilterState')->andReturn([])->byDefault();
    $livewire->shouldReceive('isTableLoaded')->andReturnTrue()->byDefault();
    $livewire->shouldReceive('getTableArguments')->andReturn([])->byDefault();

    $table = $resource::table(Table::make($livewire));

    expect($table->getExtraAttributes())->toMatchArray([
        'data-tour-id' => $tourId,
    ]);
})->with([
    'sites' => [SiteResource::class, 'welcome-tour-sites'],
    'pages' => [PageResource::class, 'welcome-tour-pages'],
    'themes' => [ThemeResource::class, 'welcome-tour-themes'],
    'media' => [MediaResource::class, 'welcome-tour-media'],
]);
