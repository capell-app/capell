<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Sites\Actions\ExportSitesBulkAction;
use Capell\Admin\Policies\SitePolicy;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class)
    ->group('site');

it('export sites bulk action requires the site export permission', function (): void {
    Permission::findOrCreate('site.export');
    Gate::policy(Site::class, SitePolicy::class);

    test()->actingAsUser();

    expect(ExportSitesBulkAction::make()->model(Site::class)->isAuthorized())->toBeFalse();
});
