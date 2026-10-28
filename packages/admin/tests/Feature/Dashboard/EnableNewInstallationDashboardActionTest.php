<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\EnableNewInstallationDashboardAction;
use Capell\Core\Actions\Install\DetectInitialInstallationAction;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Models\Site;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->environmentDirectory = storage_path('framework/testing/dashboard-env-' . bin2hex(random_bytes(6)));
    File::ensureDirectoryExists($this->environmentDirectory);
    $this->environmentPath = $this->environmentDirectory . '/.env';
    file_put_contents($this->environmentPath, "APP_NAME=Example\n");
});

afterEach(function (): void {
    File::deleteDirectory($this->environmentDirectory);
});

it('persists the fresh installation default once across repeated install calls', function (): void {
    CapellExtension::query()->whereIn('composer_name', ['capell-app/core', 'capell-app/admin'])->delete();

    expect(EnableNewInstallationDashboardAction::run(DetectInitialInstallationAction::run(), $this->environmentPath))->toBeTrue();
    $saved = file_get_contents($this->environmentPath);
    expect($saved)->toContain('CAPELL_WORK_FIRST_DASHBOARD=true')
        ->and(EnableNewInstallationDashboardAction::run(DetectInitialInstallationAction::run(), $this->environmentPath))->toBeFalse()
        ->and(file_get_contents($this->environmentPath))->toBe($saved);
});

it('leaves established installations opted out', function (): void {
    CapellExtension::query()->updateOrCreate(['composer_name' => 'capell-app/core'], [
        'name' => 'Capell Core',
        'status' => ExtensionStatusEnum::Enabled,
        'installed_at' => now(),
    ]);

    expect(EnableNewInstallationDashboardAction::run(DetectInitialInstallationAction::run(), $this->environmentPath))->toBeFalse()
        ->and(file_get_contents($this->environmentPath))->toBe("APP_NAME=Example\n");
});

it('preserves an explicit opt-out during first install and resume', function (): void {
    CapellExtension::query()->whereIn('composer_name', ['capell-app/core', 'capell-app/admin'])->delete();
    file_put_contents($this->environmentPath, "CAPELL_WORK_FIRST_DASHBOARD=false\n");

    expect(EnableNewInstallationDashboardAction::run(DetectInitialInstallationAction::run(), $this->environmentPath))->toBeFalse()
        ->and(file_get_contents($this->environmentPath))->toBe("CAPELL_WORK_FIRST_DASHBOARD=false\n");
});

it('does not opt older sites in when they have no Core completion record', function (): void {
    CapellExtension::query()->whereIn('composer_name', ['capell-app/core', 'capell-app/admin'])->delete();
    Site::factory()->create();

    expect(EnableNewInstallationDashboardAction::run(DetectInitialInstallationAction::run(), $this->environmentPath))->toBeFalse()
        ->and(file_get_contents($this->environmentPath))->toBe("APP_NAME=Example\n");
});
