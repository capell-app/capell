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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

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

it('stops the install plan stage when a required command fails', function (string $step, string $command, string $successMessage, array $expectedCalls): void {
    $calls = [];

    foreach (['db:wipe {--force}', 'storage:link', 'session:table', 'notifications:table', 'capell:xml-sitemap'] as $signature) {
        $name = explode(' ', $signature)[0];
        Artisan::command($signature, function () use ($name, $command, &$calls): int {
            $calls[] = $name;

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

    expect($calls)->toBe($expectedCalls)
        ->and($reporter->lines)->not->toContain(['report', $successMessage])
        ->and($reporter->lines)->toContain(['error', 'Required operation was rejected.']);
    Event::assertNotDispatched(DatabaseSchemaChanged::class);
})->with([
    'database wipe' => [InstallPlan::STEP_PREPARE_FRESH_INSTALL, 'db:wipe', 'Database refreshed.', ['db:wipe']],
    'storage link' => [InstallPlan::STEP_PREPARE_ENVIRONMENT, 'storage:link', '✓ Storage linked', ['storage:link']],
    'session migration' => [InstallPlan::STEP_PREPARE_ENVIRONMENT, 'session:table', '✓ Session table created', ['storage:link', 'session:table']],
    'notification migration' => [InstallPlan::STEP_PREPARE_ENVIRONMENT, 'notifications:table', '✓ Notifications table created', ['storage:link', 'session:table', 'notifications:table']],
    'sitemap' => [InstallPlan::STEP_GENERATE_SITEMAP, 'capell:xml-sitemap', '✓ Sitemaps generated', ['capell:xml-sitemap']],
]);
