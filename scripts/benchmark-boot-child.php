<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Bootstrap\RegisterFacades;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

$arguments = $GLOBALS['argv'] ?? [];
$root = $arguments[1] ?? throw new InvalidArgumentException('The repository path is required.');
$basePath = $arguments[2] ?? throw new InvalidArgumentException('The application path is required.');

require $root . '/vendor/autoload.php';

final class ProfilingBootApplication extends Application
{
    /** @var array<class-string, array{register?: float, boot?: float}> */
    public array $providerTimings = [];

    #[Override]
    public function register($provider, $force = false): ServiceProvider
    {
        $startedAt = hrtime(true);
        $registered = parent::register($provider, $force);
        $class = $registered::class;
        $this->providerTimings[$class]['register'] = ($this->providerTimings[$class]['register'] ?? 0.0)
            + ((hrtime(true) - $startedAt) / 1_000_000);

        return $registered;
    }

    #[Override]
    protected function bootProvider(ServiceProvider $provider): void
    {
        $startedAt = hrtime(true);
        parent::bootProvider($provider);
        $class = $provider::class;
        $this->providerTimings[$class]['boot'] = ($this->providerTimings[$class]['boot'] ?? 0.0)
            + ((hrtime(true) - $startedAt) / 1_000_000);
    }
}

$startedAt = hrtime(true);
$providers = require $basePath . '/bootstrap/providers.php';
$app = ProfilingBootApplication::configure($basePath)
    ->withProviders($providers, withBootstrapProviders: false)
    ->withExceptions()
    ->create();
throw_unless($app instanceof ProfilingBootApplication, RuntimeException::class, 'The profiling application could not be created.');
$app->instance('request', Request::create('/'));
$app->bootstrapWith([
    LoadEnvironmentVariables::class,
    LoadConfiguration::class,
    HandleExceptions::class,
    RegisterFacades::class,
    RegisterProviders::class,
    BootProviders::class,
]);
$elapsed = (hrtime(true) - $startedAt) / 1_000_000;

echo json_encode([
    'framework_ms' => round($elapsed, 3),
    'providers_ms' => $app->providerTimings,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
