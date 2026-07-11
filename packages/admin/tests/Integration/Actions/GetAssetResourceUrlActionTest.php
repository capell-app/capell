<?php

declare(strict_types=1);

use Capell\Admin\Actions\GetAssetResourceUrlAction;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Exceptions\ResourceNotFoundException;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Tests\Fixtures\ExampleResource;
use Illuminate\Support\Facades\Route;

it('resolves asset resource URL by type', function (): void {
    Route::name('filament.admin.resources.examples.edit')
        ->get('/admin/examples/{record}/edit', fn (): string => 'edit page');

    CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::resource(ExampleResource::class, group: 'Example'));
    $url = GetAssetResourceUrlAction::run('example', 1);
    expect($url)->toBeString()->toContain('/examples/1/edit');
});

it('resolves page resource URL by enum', function (): void {
    CapellAdmin::contributeToAdminSurface(
        AdminSurfaceContributionData::resource(PageResource::class, group: PageResource::class),
    );
    $url = GetAssetResourceUrlAction::run(PageResource::class, 1);
    expect($url)->toBeString()->toContain('page');
});

it('throws ResourceNotFoundException for unknown type', function (): void {
    expect(fn () => GetAssetResourceUrlAction::run('unknown', 1))
        ->toThrow(ResourceNotFoundException::class);
});
