<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\EnsureDatabaseExistsAction;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Events\DatabaseSchemaChanged;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Core\Support\Install\InstallRunState;
use Capell\Core\Support\Install\InstallStepExecutor;
use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Capell\Core\Tests\Support\Fixtures\Autoload\InstallSupportActionReporter;
use Capell\Core\Tests\Support\Stubs\FakeMigrationFilesystem;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Registers the stub directly on the resolved console application. Artisan::command()
 * only queues a starting callback, which never runs once Artisan::all() has
 * resolved the application.
 *
 * @param-closure-this ClosureCommand $callback
 */
function registerRequiredInstallCommandStub(string $signature, Closure $callback): void
{
    Artisan::registerCommand(new ClosureCommand($signature, $callback));
}

beforeEach(function (): void {
    $this->originalBasePath = app()->basePath();
    $this->originalDatabasePath = app()->databasePath();
    $this->temporaryBasePath = storage_path('framework/testing/required-install-' . bin2hex(random_bytes(8)));
    File::ensureDirectoryExists($this->temporaryBasePath . '/database/migrations');
    app()->setBasePath($this->temporaryBasePath);
    app()->useDatabasePath($this->temporaryBasePath . '/database');

    $migrator = Mockery::mock(Migrator::class);
    $migrator->shouldReceive('paths')->andReturn([]);
    app()->instance('migrator', $migrator);
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);
    Schema::shouldReceive('hasTable')->with('sessions')->andReturn(false);
    Schema::shouldReceive('hasTable')->with('notifications')->andReturn(false);
    bindFakeAction(EnsureDatabaseExistsAction::class);
    Event::fake([DatabaseSchemaChanged::class]);
});

afterEach(function (): void {
    app()->setBasePath($this->originalBasePath);
    app()->useDatabasePath($this->originalDatabasePath);
    File::deleteDirectory($this->temporaryBasePath);
});

it('stops the install plan stage when a required command fails', function (string $step, string $command, string $successMessage): void {
    // Resolving the console commands registers the vendor aliases (such as
    // session:table), which would replace stubs registered before it.
    Artisan::all();

    foreach (['db:wipe {--force}', 'storage:link', 'session:table', 'notifications:table', 'capell:xml-sitemap'] as $signature) {
        $name = explode(' ', $signature)[0];
        registerRequiredInstallCommandStub($signature, function () use ($name, $command): int {
            if ($name === $command) {
                $this->error('Required operation was rejected.');

                return 17;
            }

            return 0;
        });
    }

    $reporter = new InstallSupportActionReporter;
    $state = new InstallRunState(new InstallInputData(
        siteUrl: 'https://example.test',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: true,
        generateStaticSite: false,
    ), $reporter);

    expect(fn (): InstallRunState => resolve(InstallStepExecutor::class)->execute($step, $state))
        ->toThrow(RuntimeException::class, "Artisan command '" . $command . "' failed with exit code 17.");

    expect($reporter->lines)->not->toContain(['report', $successMessage])
        ->and($reporter->lines)->toContain(['error', 'Required operation was rejected.']);
    Event::assertNotDispatched(DatabaseSchemaChanged::class);
})->with([
    'database wipe' => [InstallPlan::STEP_PREPARE_FRESH_INSTALL, 'db:wipe', 'Database refreshed.'],
    'storage link' => [InstallPlan::STEP_PREPARE_ENVIRONMENT, 'storage:link', '✓ Storage linked'],
    'session migration' => [InstallPlan::STEP_PREPARE_ENVIRONMENT, 'session:table', '✓ Session table created'],
    'notification migration' => [InstallPlan::STEP_PREPARE_ENVIRONMENT, 'notifications:table', '✓ Notifications table created'],
    'sitemap' => [InstallPlan::STEP_GENERATE_SITEMAP, 'capell:xml-sitemap', '✓ Sitemaps generated'],
]);
