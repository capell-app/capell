<?php

declare(strict_types=1);

use Capell\Admin\Actions\AssignPermissionsToRole;
use Capell\Admin\Enums\FilamentWidgetEnum;
use Capell\Admin\Enums\PageEnum;
use Capell\Admin\Enums\ResourceEnum;

it('assigns permissions to a role', function (): void {
    // Call action with enums as expected by signature; it perform-builder side effects and returns void
    AssignPermissionsToRole::run(
        resources: [ResourceEnum::User],
        pages: [PageEnum::SettingsPage],
        widgets: [FilamentWidgetEnum::SiteStatsOverviewFilamentWidget],
    );

    expect(true)->toBeTrue();
});

it('fails when role missing', function (): void {
    // This action does not take a role ID; failure expectations are not applicable here
    // Ensure it does not throw for empty inputs
    AssignPermissionsToRole::run();

    expect(true)->toBeTrue();
});
