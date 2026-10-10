<?php

declare(strict_types=1);

use Capell\Core\Actions\Upgrade\PublishPendingMigrationsAction;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Tests\Support\OwnedApplicationPaths;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->workspace = new OwnedApplicationPaths(app());
});

afterEach(function (): void {
    $this->workspace->restore();
});

it('publishes core and installed package schema and settings migrations', function (): void {
    $packagePath = $this->workspace->root . '/extension';
    File::ensureDirectoryExists($packagePath . '/database/migrations');
    File::ensureDirectoryExists($packagePath . '/database/settings');
    $schemaName = '2026_01_01_000001_create_package_table.php';
    $settingsName = '2026_01_01_000002_create_package_settings.php';
    $schema = '<?php return "package schema";';
    $settings = '<?php return "package settings";';
    File::put($packagePath . '/database/migrations/' . $schemaName, $schema);
    File::put($packagePath . '/database/settings/' . $settingsName . '.stub', $settings);
    CapellCore::registerPackage('vendor/upgrade-test', type: PackageTypeEnum::Plugin, path: $packagePath);
    CapellCore::forcePackageInstalled('vendor/upgrade-test');

    $result = PublishPendingMigrationsAction::run();

    expect($result->schemaPublished)->toBeTrue()
        ->and($result->settingsPublished)->toBeTrue()
        ->and(File::get(database_path('migrations/' . $schemaName)))->toBe($schema)
        ->and(File::get(database_path('settings/' . $settingsName)))->toBe($settings);
    foreach (['migrations' => CapellCore::getMigrations(), 'settings' => CapellCore::getSettingMigrations()] as $type => $items) {
        foreach ($items as $item) {
            expect(database_path($type . '/' . $item . '.php'))->toBeFile();
        }
    }
});

it('reports dry-run without publishing files', function (): void {
    $result = PublishPendingMigrationsAction::run(dryRun: true);

    expect($result->output)->toContain('[dry-run]')
        ->and($result->schemaPublished)->toBeFalse()
        ->and($result->settingsPublished)->toBeFalse()
        ->and(File::exists(database_path('migrations')))->toBeFalse()
        ->and(File::exists(database_path('settings')))->toBeFalse();
});

it('blocks publication for immutable releases without writing migrations', function (): void {
    config()->set('capell.release_root_mode', 'immutable');

    expect(fn (): mixed => PublishPendingMigrationsAction::run())->toThrow(
        RuntimeException::class,
        'Publishing pending Capell migrations is blocked because CAPELL_RELEASE_ROOT_MODE is immutable',
    );
    expect(File::exists(database_path('migrations')))->toBeFalse()
        ->and(File::exists(database_path('settings')))->toBeFalse();
});
