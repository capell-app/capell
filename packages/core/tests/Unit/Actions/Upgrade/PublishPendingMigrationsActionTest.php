<?php

declare(strict_types=1);

use Capell\Core\Actions\Upgrade\PublishPendingMigrationsAction;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;

it('publishes core and installed package schema and settings migrations', function (): void {
    $packagePath = sys_get_temp_dir() . '/capell-upgrade-package';
    File::deleteDirectory($packagePath);
    File::ensureDirectoryExists($packagePath . '/database/migrations');
    File::ensureDirectoryExists($packagePath . '/database/settings');
    File::put($packagePath . '/database/migrations/2026_01_01_000001_create_package_table.php', '<?php declare(strict_types=1);');
    File::put($packagePath . '/database/settings/2026_01_01_000002_create_package_settings.php.stub', '<?php declare(strict_types=1);');

    CapellCore::registerPackage(
        name: 'capell-app/upgrade-test-package',
        type: PackageTypeEnum::Plugin,
        path: $packagePath,
    );
    CapellCore::forcePackageInstalled('capell-app/upgrade-test-package');

    $calls = [];
    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('call')->andReturnUsing(function (string $command, array $parameters = []) use (&$calls): int {
        $calls[] = [$command, $parameters];

        return 0;
    });
    $kernel->shouldReceive('output')->andReturn('done');
    $this->app->instance(Kernel::class, $kernel);

    $result = PublishPendingMigrationsAction::run();

    expect($result->schemaPublished)->toBeTrue()
        ->and($result->settingsPublished)->toBeTrue()
        ->and($calls)->toContain(
            ['capell:publish-migrations', [
                '--type' => 'migrations',
                '--items' => CapellCore::getMigrations(),
                '--path' => dirname(__DIR__, 4) . '/database/migrations',
            ]],
        )
        ->and($calls)->toContain(
            ['capell:publish-migrations', [
                '--type' => 'settings',
                '--items' => CapellCore::getSettingMigrations(),
                '--path' => dirname(__DIR__, 4) . '/database/settings',
            ]],
        )
        ->and($calls)->toContain(
            ['capell:publish-migrations', [
                '--type' => 'migrations',
                '--items' => ['2026_01_01_000001_create_package_table'],
                '--path' => $packagePath . '/database/migrations',
            ]],
        )
        ->and($calls)->toContain(
            ['capell:publish-migrations', [
                '--type' => 'settings',
                '--items' => ['2026_01_01_000002_create_package_settings'],
                '--path' => $packagePath . '/database/settings',
            ]],
        );
});

it('reports dry-run without calling artisan', function (): void {
    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldNotReceive('call');

    $this->app->instance(Kernel::class, $kernel);

    $result = PublishPendingMigrationsAction::run(dryRun: true);

    expect($result->output)->toContain('[dry-run]');
});
