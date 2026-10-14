<?php

declare(strict_types=1);

use Capell\Tests\Support\PackageTestDatabaseGuard;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Request;
use Illuminate\Testing\ParallelTestingServiceProvider;
use Orchestra\Sidekick\Env;
use Orchestra\Testbench\Bootstrap\LoadConfiguration;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

Env::enablePutenv();

// Pest's Laravel argument handler excludes Testbench packages. Supply the same
// options as package:test --parallel --recreate-databases, without leaking
// ParaTest-only flags into Pest's serial shard-discovery subprocess.
foreach (['LARAVEL_PARALLEL_TESTING', 'LARAVEL_PARALLEL_TESTING_RECREATE_DATABASES'] as $option) {
    $_SERVER[$option] = '1';
    putenv($option . '=1');
}

// Discovery runs in the parent; only actual workers may recreate databases.
if (empty(Request::server('PARATEST')) || empty(Request::server('TEST_TOKEN'))) {
    return;
}

PackageTestDatabaseGuard::assertEnvironmentIsSafe();
// Only framework database callbacks are needed here. Package providers are
// booted later by the real test case with its own configuration overrides.
$application = new Application(dirname(__DIR__, 2));
$application->detectEnvironment(static fn (): string => 'testing');
(new LoadConfiguration)->bootstrap($application);
$application->make(Repository::class)->set('view.compiled');
$application->instance('files', new Filesystem);
Facade::clearResolvedInstances();
Facade::setFacadeApplication($application);
$application->register(DatabaseServiceProvider::class);
$application->register(ParallelTestingServiceProvider::class);
$application->boot();

try {
    PackageTestDatabaseGuard::assertConfigurationIsSafe($application);
    ParallelTesting::callSetUpProcessCallbacks();
} finally {
    $application->flush();
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
}
