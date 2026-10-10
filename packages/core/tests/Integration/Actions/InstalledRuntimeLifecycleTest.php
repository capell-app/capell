<?php

declare(strict_types=1);

use Capell\Core\Actions\DisablePackageAction;
use Capell\Core\Actions\EnablePackageAction;
use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Actions\RuntimeRefresh\RefreshInstalledPackageRuntimeAction;
use Capell\Core\Actions\RuntimeRefresh\RestartQueueWorkersAction;
use Capell\Core\Actions\UninstallPackageAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\Runtime\RuntimeRoleSelectionData;
use Capell\Core\Events\InstalledRuntimeRefreshed;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Octane\FlushResettableState;
use Capell\Core\Support\Diagnostics\Checks\InstalledRuntimeCheck;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageLoader;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Capell\Core\Support\Runtime\RuntimeRoleResolver;
use Capell\Core\Tests\Support\BootCallbackRuntimeFixture;
use Capell\Core\Tests\Support\ComposerDependencyRuntimeFixture;
use Capell\Core\Tests\Support\ComposerDependentRuntimeFixture;
use Capell\Core\Tests\Support\FailingBundleRuntimeFixture;
use Capell\Core\Tests\Support\LegacyEnableRuntimeFixture;
use Capell\Core\Tests\Support\LoaderDependencyRuntimeFixture;
use Capell\Core\Tests\Support\LoaderDependentRuntimeFixture;
use Capell\Core\Tests\Support\OrdinaryRuntimeChildFixture;
use Capell\Core\Tests\Support\RuntimeLifecycleFixture;
use Capell\Core\Tests\Support\SandboxInstallRuntimeFixture;
use Capell\Core\Tests\Support\UnloadedLegacyRuntimeFixture;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    CapellCore::registerPackage(RuntimeLifecycleFixture::$packageName, serviceProviderClass: RuntimeLifecycleFixture::class);
    CapellCore::forcePackageInstalled(RuntimeLifecycleFixture::$packageName, false);
});

it('activates an unguarded installed phase exactly once after installation and repeated refresh', function (): void {
    $provider = app()->register(RuntimeLifecycleFixture::class);
    expect($provider->registrations)->toBe([]);

    InstallPackageAction::run(CapellCore::getPackage(RuntimeLifecycleFixture::$packageName));
    $provider->callBootedCallbacks();
    $provider->callBootedCallbacks();

    expect($provider->registrations)->toBe(['runtime'])
        ->and(app()->getProvider(RuntimeLifecycleFixture::class))->toBe($provider);
});

it('recovers a runtime phase skipped by an application boot callback', function (): void {
    CapellCore::registerPackage(BootCallbackRuntimeFixture::$packageName, serviceProviderClass: BootCallbackRuntimeFixture::class);
    CapellCore::forcePackageInstalled(BootCallbackRuntimeFixture::$packageName, false);
    $provider = app()->register(BootCallbackRuntimeFixture::class);
    expect($provider->registrations)->toBe([]);

    InstallPackageAction::run(CapellCore::getPackage(BootCallbackRuntimeFixture::$packageName));

    expect($provider->registrations)->toBe(['runtime']);
});

it('signals retained queue workers after each completed lifecycle transition', function (string $transition): void {
    $package = CapellCore::getPackage(RuntimeLifecycleFixture::$packageName);
    if ($transition === 'uninstall') {
        CapellCore::markPackageInstalled($package->name);
    }

    Cache::forget('illuminate:queue:restart');

    match ($transition) {
        'install' => InstallPackageAction::run($package),
        'enable' => EnablePackageAction::run($package),
        'disable' => DisablePackageAction::run($package),
        'uninstall' => UninstallPackageAction::run($package),
        default => throw new LogicException('Unknown lifecycle transition.'),
    };

    expect(Cache::get('illuminate:queue:restart'))->toBeInt();
})->with(['install', 'enable', 'disable', 'uninstall']);

it('requires a fresh application after an activation failure', function (): void {
    $provider = app()->register(RuntimeLifecycleFixture::class);
    $provider->fail = true;
    CapellCore::markPackageInstalled(RuntimeLifecycleFixture::$packageName);

    expect(fn () => resolve(InstalledRuntimeLifecycle::class)->refresh())->toThrow(RuntimeException::class, 'fixture failure');
    $provider->fail = false;
    expect(fn () => resolve(InstalledRuntimeLifecycle::class)->refresh())->toThrow(RuntimeException::class, 'fresh application');
    expect($provider->registrations)->toBe([]);
});

it('surfaces activation failures belonging to another provider during package loading', function (): void {
    $provider = app()->register(RuntimeLifecycleFixture::class);
    $provider->fail = true;
    CapellCore::markPackageInstalled(RuntimeLifecycleFixture::$packageName);
    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: BootCallbackRuntimeFixture::$packageName,
        providers: ['runtime' => [BootCallbackRuntimeFixture::class]],
    ));
    CapellCore::registerManifestPackage($manifest, '1.0.0');
    CapellCore::markPackageInstalled($manifest->name);
    $registry = new CapellPackageRegistry;
    $registry->register($manifest);

    expect(fn (): array => new CapellPackageLoader(app(), $registry)->loadProviders())
        ->toThrow(RuntimeException::class, 'fixture failure');
});

it('does not retain a failed activation exception across later jobs', function (): void {
    $provider = app()->register(RuntimeLifecycleFixture::class);
    $provider->fail = true;
    CapellCore::markPackageInstalled(RuntimeLifecycleFixture::$packageName);
    $failure = null;

    try {
        resolve(InstalledRuntimeLifecycle::class)->refresh();
    } catch (RuntimeException $runtimeException) {
        $failure = WeakReference::create($runtimeException);
    }

    unset($runtimeException);

    expect($failure)->toBeInstanceOf(WeakReference::class)
        ->and($failure?->get())->toBeNull();
});

it('does not activate disabled failed or quarantined packages', function (string $state): void {
    $provider = app()->register(RuntimeLifecycleFixture::class);
    match ($state) {
        'disabled' => CapellCore::markPackageDisabled(RuntimeLifecycleFixture::$packageName),
        'failed' => CapellCore::markPackageFailed(RuntimeLifecycleFixture::$packageName, 'fixture failure'),
        'quarantined' => CapellCore::markPackageProviderQuarantined(RuntimeLifecycleFixture::$packageName, RuntimeLifecycleFixture::class, 'fixture failure'),
        default => throw new LogicException('Unknown lifecycle state.'),
    };
    resolve(InstalledRuntimeLifecycle::class)->refresh();
    expect($provider->registrations)->toBe([]);
})->with(['disabled', 'failed', 'quarantined']);

it('retains successful activation across disable and re-enable in the same application', function (): void {
    $provider = app()->register(RuntimeLifecycleFixture::class);
    InstallPackageAction::run(CapellCore::getPackage(RuntimeLifecycleFixture::$packageName));
    DisablePackageAction::run(CapellCore::getPackage(RuntimeLifecycleFixture::$packageName));
    resolve(InstalledRuntimeLifecycle::class)->refresh();
    EnablePackageAction::run(CapellCore::getPackage(RuntimeLifecycleFixture::$packageName));
    expect($provider->registrations)->toBe(['runtime']);
});

it('preserves legacy callback counts when enabling an already loaded provider', function (): void {
    CapellCore::registerPackage(LegacyEnableRuntimeFixture::$packageName, serviceProviderClass: LegacyEnableRuntimeFixture::class);
    CapellCore::markPackageInstalled(LegacyEnableRuntimeFixture::$packageName);
    $provider = app()->register(LegacyEnableRuntimeFixture::class);
    expect($provider->calls)->toBe(1);

    DisablePackageAction::run(CapellCore::getPackage(LegacyEnableRuntimeFixture::$packageName));
    EnablePackageAction::run(CapellCore::getPackage(LegacyEnableRuntimeFixture::$packageName));

    expect($provider->calls)->toBe(1);
});

it('orders required package activation before a dependent registered earlier', function (): void {
    CapellCore::registerPackage('test/dependency');
    CapellCore::getPackage(RuntimeLifecycleFixture::$packageName)->requirements = ['test/dependency'];
    $order = [];
    $runtime = resolve(InstalledRuntimeLifecycle::class);
    $runtime->register(RuntimeLifecycleFixture::class, RuntimeLifecycleFixture::$packageName, 'runtime', function () use (&$order): void {
        $order[] = 'dependent';
    });
    $runtime->register(BootCallbackRuntimeFixture::class, 'test/dependency', 'runtime', function () use (&$order): void {
        $order[] = 'dependency';
    });
    CapellCore::markPackageInstalled(RuntimeLifecycleFixture::$packageName);
    $runtime->refresh();
    expect($order)->toBe([]);
    CapellCore::markPackageInstalled('test/dependency');
    $runtime->refresh();
    $runtime->refresh();

    expect($order)->toBe(['dependency', 'dependent']);
});

it('reports every disabled requirement that prevents installed runtime activation and recovers after enable', function (): void {
    $logger = Log::spy();
    foreach (['test/disabled-dependency', 'test/other-disabled-dependency'] as $dependency) {
        CapellCore::registerPackage($dependency);
        CapellCore::markPackageInstalled($dependency);
        CapellCore::markPackageDisabled($dependency);
    }

    CapellCore::getPackage(RuntimeLifecycleFixture::$packageName)->requirements = ['test/disabled-dependency', 'test/other-disabled-dependency'];
    CapellCore::markPackageInstalled(RuntimeLifecycleFixture::$packageName);
    $provider = app()->register(RuntimeLifecycleFixture::class);
    $runtime = resolve(InstalledRuntimeLifecycle::class);
    $runtime->refresh();

    $check = resolve(InstalledRuntimeCheck::class)->check();
    expect($provider->registrations)->toBe([])
        ->and($check->passed)->toBeFalse()
        ->and($check->message)->toContain(RuntimeLifecycleFixture::$packageName, 'test/disabled-dependency', 'test/other-disabled-dependency');

    $logger->shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, RuntimeLifecycleFixture::$packageName)
        && str_contains($message, 'test/disabled-dependency')
        && $context['requirement'] === 'test/disabled-dependency');

    CapellCore::markPackageInstalled('test/disabled-dependency');
    CapellCore::markPackageInstalled('test/other-disabled-dependency');
    $runtime->refresh();
    expect($provider->registrations)->toBe(['runtime'])
        ->and(resolve(InstalledRuntimeCheck::class)->check()->passed)->toBeTrue();
});

it('does not reset the activation guard at a request or sandbox boundary', function (): void {
    $provider = app()->register(RuntimeLifecycleFixture::class);
    InstallPackageAction::run(CapellCore::getPackage(RuntimeLifecycleFixture::$packageName));
    resolve(FlushResettableState::class)->handle();
    $sandbox = clone app();
    $sandbox->make(InstalledRuntimeLifecycle::class)->refresh();
    expect($provider->registrations)->toBe(['runtime'])
        ->and($sandbox->make(InstalledRuntimeLifecycle::class))->toBe(resolve(InstalledRuntimeLifecycle::class));
    $original = app();
    $runtime = resolve(InstalledRuntimeLifecycle::class);
    $separate = new Application;
    $separate->singleton(InstalledRuntimeLifecycle::class);

    expect($separate->make(InstalledRuntimeLifecycle::class))->not->toBe($runtime);
    Container::setInstance($original);
});

it('refreshes a preloaded ordinary child provider after installation', function (): void {
    $child = app()->register(OrdinaryRuntimeChildFixture::class);
    expect($child->calls)->toBe(0);
    InstallPackageAction::run(CapellCore::getPackage(RuntimeLifecycleFixture::$packageName));
    resolve(InstalledRuntimeLifecycle::class)->refresh();
    expect($child->calls)->toBe(1)->and(app()->getProvider(OrdinaryRuntimeChildFixture::class))->toBe($child);
});

it('refuses to activate inherited providers against another application sandbox', function (): void {
    $provider = app()->register(RuntimeLifecycleFixture::class);
    CapellCore::markPackageInstalled(RuntimeLifecycleFixture::$packageName);
    $original = app();
    $sandbox = clone $original;
    Container::setInstance($sandbox);

    try {
        expect(fn () => $sandbox->make(InstalledRuntimeLifecycle::class)->refresh())
            ->toThrow(RuntimeException::class, 'owning application');
        expect($provider->registrations)->toBe([]);
    } finally {
        Container::setInstance($original);
    }

    $original->make(InstalledRuntimeLifecycle::class)->refresh();
    expect($provider->registrations)->toBe(['runtime']);
});

it('keeps public runtime refresh out of the admin bucket even for a preloaded child', function (bool $preloaded): void {
    $package = CapellCore::getPackage(RuntimeLifecycleFixture::$packageName);
    $package->manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: $package->name,
        providers: ['admin' => [OrdinaryRuntimeChildFixture::class]],
    ));
    app()->instance(RuntimeRoleResolver::class, new RuntimeRoleResolver(
        RuntimeRoleSelectionData::fromConfiguredValue('public'),
    ));
    $child = $preloaded ? app()->register(OrdinaryRuntimeChildFixture::class) : null;
    InstallPackageAction::run($package);
    resolve(InstalledRuntimeLifecycle::class)->refresh();
    expect(app()->providerIsLoaded(OrdinaryRuntimeChildFixture::class))->toBe($preloaded)
        ->and($child instanceof OrdinaryRuntimeChildFixture ? $child->calls : 0)->toBe(0);
})->with([false, true]);

it('does not activate installed runtime during package discovery', function (): void {
    $provider = app()->register(RuntimeLifecycleFixture::class);
    CapellCore::markPackageInstalled(RuntimeLifecycleFixture::$packageName);
    $previous = request()->server('argv');
    request()->server->set('argv', ['artisan', 'package:discover']);
    try {
        resolve(InstalledRuntimeLifecycle::class)->refresh();
        expect($provider->registrations)->toBe([]);
    } finally {
        request()->server->set('argv', $previous);
    }

    resolve(InstalledRuntimeLifecycle::class)->refresh();
    expect($provider->registrations)->toBe(['runtime']);
});

it('never repeats a listener or a completed provider after a mid-hook exception', function (): void {
    $runtime = resolve(InstalledRuntimeLifecycle::class);
    CapellCore::markPackageInstalled(RuntimeLifecycleFixture::$packageName);
    $calls = 0;
    $runtime->register(RuntimeLifecycleFixture::class, RuntimeLifecycleFixture::$packageName, 'runtime', function () use (&$calls): void {
        $calls++;
    });
    $runtime->register(OrdinaryRuntimeChildFixture::class, RuntimeLifecycleFixture::$packageName, 'admin', function (): void {
        resolve(Dispatcher::class)->listen('runtime.partial', static function (): void {});
        throw new RuntimeException('after listener');
    });
    expect(fn () => $runtime->refresh())->toThrow(RuntimeException::class, 'after listener');
    expect(fn () => $runtime->refresh())->toThrow(RuntimeException::class, 'fresh application');
    expect($calls)->toBe(1)
        ->and(resolve(Dispatcher::class)->getRawListeners()['runtime.partial'])->toHaveCount(1);
});

it('coalesces provider callbacks without retaining callbacks or rescanning providers', function (bool $installed): void {
    $provider = app()->register(RuntimeLifecycleFixture::class);
    if ($installed) {
        InstallPackageAction::run(CapellCore::getPackage(RuntimeLifecycleFixture::$packageName));
    }

    $callbacks = new ReflectionProperty(app(), 'bootedCallbacks');
    $before = count($callbacks->getValue(app()));
    CapellCore::shouldReceive('isPackageEnabled')->never();
    for ($iteration = 0; $iteration < 100; $iteration++) {
        $provider->callBootedCallbacks();
    }

    expect(count($callbacks->getValue(app())))->toBe($before)
        ->and($provider->registrations)->toBe($installed ? ['runtime'] : []);
})->with([false, true]);

it('orders provider enrolment through the loader in an already booted application', function (): void {
    LoaderDependentRuntimeFixture::$order = [];
    $registry = new CapellPackageRegistry;
    foreach (['test/loader-dependent' => LoaderDependentRuntimeFixture::class, 'test/loader-dependency' => LoaderDependencyRuntimeFixture::class] as $name => $provider) {
        $manifest = CapellManifestData::fromArray(capellManifestV3Array(name: $name, providers: ['runtime' => [$provider]]));
        CapellCore::registerManifestPackage($manifest, '1.0.0');
        CapellCore::markPackageInstalled($name);
        $registry->register($manifest);
    }

    CapellCore::getPackage('test/loader-dependent')->requirements = ['test/loader-dependency'];
    new CapellPackageLoader(app(), $registry)->loadProviders();
    expect(LoaderDependentRuntimeFixture::$order)->toBe(['dependency', 'dependent']);
});

it('does not boot an unloaded legacy provider when enabling its package', function (): void {
    $manifest = CapellManifestData::fromArray(capellManifestV3Array(name: 'test/unloaded-legacy', providers: ['runtime' => [UnloadedLegacyRuntimeFixture::class]]));
    CapellCore::registerManifestPackage($manifest, '1.0.0');
    CapellCore::markPackageDisabled($manifest->name);
    EnablePackageAction::run(CapellCore::getPackage($manifest->name));
    expect(app()->providerIsLoaded(UnloadedLegacyRuntimeFixture::class))->toBeFalse();
});

it('does not refresh panel surfaces when enabling a non-adopting package', function (): void {
    Event::fake([InstalledRuntimeRefreshed::class]);
    CapellCore::registerPackage('test/nonadopting', serviceProviderClass: LegacyEnableRuntimeFixture::class);
    EnablePackageAction::run(CapellCore::getPackage('test/nonadopting'));
    Event::assertNotDispatched(InstalledRuntimeRefreshed::class);
});

it('rejects sandbox lifecycle operations before any member state or install side effect changes', function (string $operation): void {
    $package = CapellCore::getPackage(RuntimeLifecycleFixture::$packageName);
    $package->installAction = SandboxInstallRuntimeFixture::class;

    SandboxInstallRuntimeFixture::$calls = 0;
    $package->kind = 'bundle';
    CapellCore::registerPackage('test/sandbox-member');
    $package->requirements = ['test/sandbox-member'];
    $original = app();
    resolve(InstalledRuntimeLifecycle::class);
    Container::setInstance(clone $original);
    try {
        expect(fn () => $operation === 'install' ? InstallPackageAction::run($package) : EnablePackageAction::run($package))
            ->toThrow(RuntimeException::class, 'owning application');
    } finally {
        Container::setInstance($original);
    }

    expect(SandboxInstallRuntimeFixture::$calls)->toBe(0)
        ->and(CapellCore::isPackageEnabled($package->name))->toBeFalse()
        ->and(CapellCore::isPackageEnabled('test/sandbox-member'))->toBeFalse();
})->with(['install', 'enable']);

it('signals restart once after a whole bundle and never during boot or callback replay', function (): void {
    $cache = Mockery::mock(Repository::class);
    $cache->shouldReceive('forever')->once()->with('illuminate:queue:restart', Mockery::type('int'))->andReturnTrue();
    app()->instance(RestartQueueWorkersAction::class, new RestartQueueWorkersAction($cache));
    $provider = app()->register(RuntimeLifecycleFixture::class);
    $provider->callBootedCallbacks();

    $bundle = CapellCore::getPackage(RuntimeLifecycleFixture::$packageName);
    $bundle->kind = 'bundle';
    foreach (['test/member-one', 'test/member-two'] as $name) {
        CapellCore::registerPackage($name);
    }

    $bundle->requirements = ['test/member-one', 'test/member-two'];
    InstallPackageAction::run($bundle);
    $provider->callBootedCallbacks();
    resolve(InstalledRuntimeLifecycle::class)->refresh();
    Route::get('/runtime-request', static fn (): string => 'ready');
    $this->get('/runtime-request')->assertOk();
});

it('does not signal a restart for an incomplete bundle after a member succeeded', function (): void {
    $cache = Mockery::mock(Repository::class);
    $cache->shouldNotReceive('forever');

    app()->instance(RestartQueueWorkersAction::class, new RestartQueueWorkersAction($cache));
    $bundle = CapellCore::getPackage(RuntimeLifecycleFixture::$packageName);
    $bundle->kind = 'bundle';
    foreach (['test/member-one', 'test/member-two'] as $name) {
        CapellCore::registerPackage($name);
    }

    $bundle->requirements = ['test/member-one', 'test/member-two'];
    CapellCore::getPackage('test/member-two')->installAction = FailingBundleRuntimeFixture::class;
    expect(fn () => InstallPackageAction::run($bundle))->toThrow(RuntimeException::class, 'member failed');
    expect(CapellCore::isPackageInstalled('test/member-one'))->toBeFalse();
});

it('round three waits for Composer main adopters outside manifest buckets', function (): void {
    LoaderDependentRuntimeFixture::$order = [];
    foreach (['test/loader-dependent' => ComposerDependentRuntimeFixture::class, 'test/loader-dependency' => ComposerDependencyRuntimeFixture::class] as $name => $provider) {
        CapellCore::registerPackage($name, serviceProviderClass: $provider);
        CapellCore::markPackageInstalled($name);
    }

    CapellCore::getPackage('test/loader-dependent')->requirements = ['test/loader-dependency'];
    app()->register(ComposerDependentRuntimeFixture::class);
    expect(LoaderDependentRuntimeFixture::$order)->toBe([]);
    app()->register(ComposerDependencyRuntimeFixture::class);
    expect(LoaderDependentRuntimeFixture::$order)->toBe(['dependency', 'dependent']);
});

it('round three clears persisted activation caches before success for every operation', function (string $operation): void {
    $package = CapellCore::getPackage(RuntimeLifecycleFixture::$packageName);
    app()->register(RuntimeLifecycleFixture::class);
    $routes = resolve(Router::class)->getRoutes();
    throw_unless($routes instanceof RouteCollection, RuntimeException::class, 'Expected uncached fixture routes.');
    $paths = [app()->getCachedRoutesPath(), app()->getCachedConfigPath()];
    foreach ($paths as $path) {
        file_put_contents($path, '<?php return ' . var_export($path === app()->getCachedRoutesPath() ? $routes->compile() : config()->all(), true) . ';');
    }

    try {
        match ($operation) {
            'install' => InstallPackageAction::run($package),
            'enable' => EnablePackageAction::run($package),
            'refresh' => RefreshInstalledPackageRuntimeAction::run($package),
            default => throw new LogicException('Unknown runtime operation.'),
        };
        foreach ($paths as $path) {
            expect(file_exists($path))->toBeFalse();
        }
    } finally {
        foreach ($paths as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
})->with(['install', 'enable', 'refresh']);

it('round three logs a caught hook cause once with diagnostic context', function (): void {
    $provider = app()->register(RuntimeLifecycleFixture::class);
    $provider->fail = true;
    CapellCore::markPackageInstalled(RuntimeLifecycleFixture::$packageName);
    $logger = Log::spy();
    $runtime = resolve(InstalledRuntimeLifecycle::class);
    expect(fn () => $runtime->refresh())->toThrow(RuntimeException::class, 'fixture failure');
    expect(fn () => $runtime->refresh())->toThrow(RuntimeException::class);
    $logger->shouldHaveReceived('error')->once()->withArgs(static fn (string $message, array $context): bool => $context['package'] === RuntimeLifecycleFixture::$packageName
        && $context['provider'] === RuntimeLifecycleFixture::class && $context['step'] === 'installed-runtime-hook'
        && is_string($context['exception']) && str_contains($context['exception'], 'fixture failure'));
});

it('reports pending retained process activation through the install progress channel', function (): void {
    config(['octane.server' => 'swoole']);
    $reporter = Mockery::mock(ProgressReporter::class);
    $reporter->shouldReceive('report')->once()->with(__('capell-core::runtime-refresh.retained_reload_required'));
    InstallPackageAction::run(CapellCore::getPackage(RuntimeLifecycleFixture::$packageName), reporter: $reporter);
});

it('continues independent bootstrap activation after a hook fails', function (): void {
    $runtime = resolve(InstalledRuntimeLifecycle::class);
    CapellCore::markPackageInstalled(RuntimeLifecycleFixture::$packageName);
    CapellCore::registerPackage('test/independent-runtime');
    CapellCore::markPackageInstalled('test/independent-runtime');
    $runtime->register(RuntimeLifecycleFixture::class, RuntimeLifecycleFixture::$packageName, 'runtime', static function (): void {
        throw new RuntimeException('one failed package');
    });
    $ran = false;
    $runtime->register(OrdinaryRuntimeChildFixture::class, 'test/independent-runtime', 'runtime', static function () use (&$ran): void {
        $ran = true;
    });
    $runtime->refreshForBootstrap();
    expect($ran)->toBeTrue()->and(CapellCore::isPackageInstalled(RuntimeLifecycleFixture::$packageName))->toBeFalse();
});

it('does not log the original hook exception again through the exception handler', function (): void {
    $provider = app()->register(RuntimeLifecycleFixture::class);
    $provider->fail = true;
    CapellCore::markPackageInstalled(RuntimeLifecycleFixture::$packageName);
    $logger = Log::spy();
    try {
        resolve(InstalledRuntimeLifecycle::class)->refresh();
    } catch (RuntimeException $runtimeException) {
        report($runtimeException);
    }

    $logger->shouldHaveReceived('error')->once();
});

it('keeps unrelated lifecycle commands available after a package hook failure', function (string $operation): void {
    $provider = app()->register(RuntimeLifecycleFixture::class);
    $provider->fail = true;
    CapellCore::markPackageInstalled(RuntimeLifecycleFixture::$packageName);
    expect(fn () => resolve(InstalledRuntimeLifecycle::class)->refresh())->toThrow(RuntimeException::class);
    CapellCore::registerPackage('test/unrelated-operation');
    $package = CapellCore::getPackage('test/unrelated-operation');
    match ($operation) {
        'install' => InstallPackageAction::run($package),
        'enable' => EnablePackageAction::run($package),
        'refresh' => RefreshInstalledPackageRuntimeAction::run($package),
        default => throw new LogicException('Unknown runtime operation.'),
    };
    expect(resolve(InstalledRuntimeLifecycle::class)->packageFailed($package->name))->toBeFalse();
})->with(['install', 'enable', 'refresh']);
