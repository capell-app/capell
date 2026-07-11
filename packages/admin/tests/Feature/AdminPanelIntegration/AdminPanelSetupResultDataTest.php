<?php

declare(strict_types=1);

use Capell\Admin\Data\AdminPanelIntegration\AdminPanelChangeResultData;
use Capell\Admin\Data\AdminPanelIntegration\AdminPanelSetupResultData;
use Capell\Admin\Enums\AdminPanelChangeStatus;
use Capell\Admin\Enums\AdminPanelFailureCategory;

it('summarises applied failed and manual changes', function (): void {
    $result = new AdminPanelSetupResultData(
        panelPath: app_path('Providers/Filament/AdminPanelProvider.php'),
        backupPath: null,
        changes: [
            new AdminPanelChangeResultData('plugin', AdminPanelChangeStatus::Applied, 'Added CapellAdminPlugin.'),
            new AdminPanelChangeResultData('colors', AdminPanelChangeStatus::Applied, 'Added Capell colors.'),
            new AdminPanelChangeResultData('widgets', AdminPanelChangeStatus::Failed, 'Could not parse widgets chain.', AdminPanelFailureCategory::ParseError),
            new AdminPanelChangeResultData('navigation', AdminPanelChangeStatus::Manual, 'Add navigation items and groups manually.'),
        ],
        docsUrl: 'https://capellcms.com/docs/admin-setup',
    );

    expect($result->hasFailures())->toBeTrue()
        ->and($result->appliedCount())->toBe(2)
        ->and($result->manualCount())->toBe(1);
});
