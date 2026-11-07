<?php

declare(strict_types=1);

use Capell\Admin\Actions\Extensions\BuildExtensionOperationsSummaryAction;
use Capell\Admin\Actions\Extensions\ResolveExtensionAssetPathAction;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Support\Extensions\ExtensionNavigationBadgeCounts;
use Capell\Core\Enums\ExtensionHealthAlertCategory;
use Capell\Core\Enums\ExtensionHealthAlertSeverity;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Models\ExtensionHealthAlert;
use Capell\Core\Support\Extensions\ExtensionLifecycleRepository;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(CreatesAdminUser::class)->group('extension');

function countingBadgeCounts(): object
{
    $counts = new class extends ExtensionNavigationBadgeCounts
    {
        public int $summaryBuilds = 0;

        #[Override]
        protected function build(): array
        {
            $this->summaryBuilds++;

            return ['unhealthy' => 2, 'blocked' => 1];
        }
    };
    app()->instance(ExtensionNavigationBadgeCounts::class, $counts);

    return $counts;
}

beforeEach(function (): void {
    Cache::flush();
    test()->actingAsAdmin();
});

it('does not rebuild the extension summary for repeated badge reads', function (): void {
    $counts = countingBadgeCounts();

    expect(ExtensionsPage::getNavigationBadge())->toBe('2')
        ->and(ExtensionsPage::getNavigationBadgeColor())->toBe('danger')
        ->and($counts->summaryBuilds)->toBe(1);

    // A fresh instance models the next request: the count still comes from cache.
    $next = countingBadgeCounts();
    ExtensionsPage::getNavigationBadge();

    expect($next->summaryBuilds)->toBe(0);
});

it('rebuilds the badge counts only after extensions change', function (): void {
    $counts = countingBadgeCounts();
    ExtensionsPage::getNavigationBadge();

    CapellExtension::query()->create([
        'composer_name' => 'vendor/badge-demo', 'name' => 'Badge demo', 'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled, 'installed_at' => now(),
    ]);
    $afterChange = countingBadgeCounts();
    ExtensionsPage::getNavigationBadge();
    ExtensionsPage::getNavigationBadge();

    expect($counts->summaryBuilds)->toBe(1)
        ->and($afterChange->summaryBuilds)->toBe(1);
});

it('does not consult badge counts for a user without permission', function (): void {
    test()->actingAsUser();
    $counts = countingBadgeCounts();

    expect(ExtensionsPage::getNavigationBadge())->toBeNull()
        ->and(ExtensionsPage::getNavigationBadgeColor())->toBeNull()
        ->and($counts->summaryBuilds)->toBe(0);
});

it('invalidates counts on lifecycle uninstall', function (): void {
    $extension = CapellExtension::query()->create([
        'composer_name' => 'vendor/badge-uninstall', 'name' => 'Badge uninstall', 'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled, 'installed_at' => now(),
    ]);
    $counts = countingBadgeCounts();
    ExtensionsPage::getNavigationBadge();
    resolve(ExtensionLifecycleRepository::class)->delete($extension->composer_name);
    $next = countingBadgeCounts();
    ExtensionsPage::getNavigationBadge();

    expect($counts->summaryBuilds)->toBe(1)->and($next->summaryBuilds)->toBe(1);
});

it('does not resolve screenshot assets for admin navigation', function (): void {
    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/badge-screenshot',
        surfaces: ['admin'],
        overrides: ['marketplace' => ['screenshots' => [['path' => 'images/screenshot.jpg', 'alt' => 'Screenshot']]]],
    ));
    resolve(CapellPackageRegistry::class)->register($manifest);
    $assets = bindFakeAction(ResolveExtensionAssetPathAction::class);
    test()->get('/admin')->assertOk();
    expect($assets->called)->toBeFalse();

    BuildExtensionOperationsSummaryAction::run();
    expect($assets->called)->toBeTrue();
});

it('invalidates cached counts for extension and health changes', function (): void {
    $extension = CapellExtension::query()->create([
        'composer_name' => 'vendor/badge-events', 'name' => 'Badge events', 'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled, 'installed_at' => now(),
    ]);
    $counts = countingBadgeCounts();
    ExtensionsPage::getNavigationBadge();

    $extension->update(['status' => ExtensionStatusEnum::Disabled]);
    ExtensionsPage::getNavigationBadge();
    $extension->update(['status' => ExtensionStatusEnum::Enabled, 'version' => '2.0.0']);
    ExtensionsPage::getNavigationBadge();
    $alert = ExtensionHealthAlert::query()->create([
        'alert_id' => 'badge-events', 'source' => 'marketplace', 'composer_name' => $extension->composer_name,
        'severity' => ExtensionHealthAlertSeverity::Warning, 'category' => ExtensionHealthAlertCategory::Security,
        'title' => 'Alert', 'message' => 'Alert', 'signature' => 'test', 'issued_at' => now(),
    ]);
    ExtensionsPage::getNavigationBadge();
    $alert->delete();
    ExtensionsPage::getNavigationBadge();

    expect($counts->summaryBuilds)->toBe(5);
});

it('invalidates again after an extension transaction commits', function (): void {
    $extension = CapellExtension::query()->create([
        'composer_name' => 'vendor/badge-transaction', 'name' => 'Badge transaction', 'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled, 'installed_at' => now(),
    ]);
    $counts = countingBadgeCounts();
    ExtensionsPage::getNavigationBadge();

    DB::transaction(function () use ($extension): void {
        $extension->update(['status' => ExtensionStatusEnum::Disabled]);
        ExtensionsPage::getNavigationBadge();
    });
    ExtensionsPage::getNavigationBadge();

    expect($counts->summaryBuilds)->toBe(3);
});

it('uses the persistent cache on the next admin request', function (): void {
    $directory = base_path('var/badge-cache-' . bin2hex(random_bytes(8)));
    File::ensureDirectoryExists($directory);
    config(['cache.default' => 'badge_test', 'cache.stores.badge_test' => [
        'driver' => 'file', 'path' => $directory, 'lock_path' => $directory,
    ]]);

    try {
        $first = countingBadgeCounts();
        test()->get('/admin')->assertOk();
        expect(ExtensionsPage::getNavigationBadge())->toBe('2');
        $next = countingBadgeCounts();
        test()->get('/admin')->assertOk();
        expect(ExtensionsPage::getNavigationBadge())->toBe('2');

        expect($first->summaryBuilds)->toBe(1)->and($next->summaryBuilds)->toBe(0);
    } finally {
        File::deleteDirectory($directory);
    }
});
