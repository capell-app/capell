<?php

declare(strict_types=1);

use Capell\Installer\Support\InstallGuide\Patches\AdminPanelDashboardPatch;
use Capell\Installer\Support\InstallGuide\PatchStatus;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->testDirectory = sys_get_temp_dir() . '/capell-admin-dashboard-patch-test-' . uniqid();

    mkdir($this->testDirectory, 0755, true);
    $this->app->setBasePath($this->testDirectory);
});

afterEach(function (): void {
    if (is_dir($this->testDirectory)) {
        File::deleteDirectory($this->testDirectory);
    }
});

it('replaces the stock filament dashboard page with the Capell dashboard page', function (): void {
    $providerPath = base_path('app/Providers/Filament/AdminPanelProvider.php');
    $providerDirectory = dirname($providerPath);

    if (! is_dir($providerDirectory)) {
        mkdir($providerDirectory, 0755, true);
    }

    file_put_contents($providerPath, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->pages([Dashboard::class])
            ->login();
    }
}
PHP);

    $patch = new AdminPanelDashboardPatch;

    expect($patch->probe())->toBe(PatchStatus::Applicable);

    $patch->apply();

    $updatedContent = (string) file_get_contents($providerPath);

    expect($patch->probe())->toBe(PatchStatus::AlreadyApplied)
        ->and($updatedContent)->toContain('use Capell\Admin\Filament\Pages\CapellDashboard;')
        ->and($updatedContent)->not->toContain('use Filament\Pages\Dashboard;')
        ->and($updatedContent)->toContain('->pages([CapellDashboard::class])');
});
