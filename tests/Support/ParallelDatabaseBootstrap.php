<?php

declare(strict_types=1);

use Capell\Tests\Support\PackageTestDatabaseGuard;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Request;
use Illuminate\Testing\ParallelTestingServiceProvider;
use Orchestra\Sidekick\Env;
use Orchestra\Testbench\Foundation\Application;

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
$application = Application::create(options: [
    'extra' => [
        'env' => ['APP_ENV' => 'testing'],
        'dont-discover' => ['*'],
    ],
]);
$application->make(Repository::class)->set('view.compiled');
$application->register(ParallelTestingServiceProvider::class);

try {
    PackageTestDatabaseGuard::assertConfigurationIsSafe($application);
    ParallelTesting::callSetUpProcessCallbacks();
} finally {
    $application->terminate();
    HandleExceptions::flushState();
    $application->flush();
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
}
