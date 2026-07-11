<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Layouts\Pages\ListLayouts;
use Capell\Core\Models\Layout;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;

uses(CreatesAdminUser::class)
    ->group('layout');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

test('ranks layout name matches before key matches', function (): void {
    $search = 'capell-layout-table-relevance';

    $nameMatch = Layout::factory()->createOne([
        'name' => $search . ' name match',
        'key' => 'layout-name-match',
    ]);
    $keyMatch = Layout::factory()->createOne([
        'name' => 'Z layout key match',
        'key' => $search . '-key-match',
    ]);

    Livewire::test(ListLayouts::class)
        ->assertSuccessful()
        ->searchTable($search)
        ->assertCanSeeTableRecords([$nameMatch, $keyMatch], inOrder: true);
});
