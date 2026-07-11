<?php

declare(strict_types=1);

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Support\CapellAdminManager;
use Capell\Core\Actions\GetResourceFromBlueprintAction;
use Capell\Core\Facades\CapellCore;

beforeEach(function (): void {
    CapellCore::shouldReceive('hasPackage')->andReturn(true);

    app()->forgetInstance(CapellAdminManager::class);
    CapellAdmin::clearResolvedInstance(CapellAdminManager::class);
    CapellAdmin::clearAdminSurfaceContributions();
    CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::resource(PageResource::class, 'Page'));
});

it('maps type to resource', function (): void {
    $resource = GetResourceFromBlueprintAction::run('Page');

    expect($resource)->toBeString()->toContain('Page');
});

it('throws on invalid type', function (): void {
    expect(fn () => GetResourceFromBlueprintAction::run('Unknown'))
        ->toThrow(InvalidArgumentException::class, 'Resource not found for type: Unknown, name: default');
});
