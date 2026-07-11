<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\PrepareEnvironmentAction;
use Capell\Core\Support\Install\NullProgressReporter;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

it('runs storage link and session table commands', function (): void {
    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('call')->with('storage:link')->once()->andReturn(0);
    $kernel->shouldReceive('call')->with('session:table')->once()->andReturn(0);
    $kernel->shouldReceive('call')->with('notifications:table')->zeroOrMoreTimes()->andReturn(0);

    $this->app->instance(ConsoleKernel::class, $kernel);

    Schema::shouldReceive('hasTable')->with('notifications')->andReturn(true);

    PrepareEnvironmentAction::run(new NullProgressReporter);
});

it('creates notifications table when it does not exist', function (): void {
    $originalDatabasePath = database_path();
    $isolatedDatabasePath = storage_path('framework/testing/prepare-environment-empty-database');
    File::deleteDirectory($isolatedDatabasePath);
    File::ensureDirectoryExists($isolatedDatabasePath . '/migrations');

    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('call')->with('storage:link')->once()->andReturn(0);
    $kernel->shouldReceive('call')->with('session:table')->once()->andReturn(0);
    $kernel->shouldReceive('call')->with('notifications:table')->once()->andReturn(0);

    $this->app->instance(ConsoleKernel::class, $kernel);

    Schema::shouldReceive('hasTable')->with('notifications')->andReturn(false);

    try {
        $this->app->useDatabasePath($isolatedDatabasePath);

        PrepareEnvironmentAction::run(new NullProgressReporter);
    } finally {
        $this->app->useDatabasePath($originalDatabasePath);
        File::deleteDirectory($isolatedDatabasePath);
    }
});

it('skips notifications table when it already exists', function (): void {
    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('call')->with('storage:link')->once()->andReturn(0);
    $kernel->shouldReceive('call')->with('session:table')->once()->andReturn(0);
    $kernel->shouldReceive('call')->with('notifications:table')->never();

    $this->app->instance(ConsoleKernel::class, $kernel);

    Schema::shouldReceive('hasTable')->with('notifications')->andReturn(true);

    PrepareEnvironmentAction::run(new NullProgressReporter);
});

it('skips notifications table when a notifications migration already exists', function (): void {
    $migrationPath = database_path('migrations/2035_01_01_000000_create_notifications_table.php');

    File::ensureDirectoryExists(dirname($migrationPath));
    File::put($migrationPath, '<?php declare(strict_types=1);');

    try {
        $kernel = Mockery::mock(ConsoleKernel::class);
        $kernel->shouldReceive('call')->with('storage:link')->once()->andReturn(0);
        $kernel->shouldReceive('call')->with('session:table')->once()->andReturn(0);
        $kernel->shouldReceive('call')->with('notifications:table')->never();

        $this->app->instance(ConsoleKernel::class, $kernel);

        Schema::shouldReceive('hasTable')->with('notifications')->andReturn(false);

        PrepareEnvironmentAction::run(new NullProgressReporter);
    } finally {
        File::delete($migrationPath);
    }
});
