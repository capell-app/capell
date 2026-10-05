<?php

declare(strict_types=1);

use Capell\Core\Actions\DisablePackageAction;
use Capell\Core\Actions\EnablePackageAction;
use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Actions\UninstallPackageAction;
use Capell\Core\Data\Runtime\RuntimeRoleSelectionData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Octane\FlushResettableState;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageLoader;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Capell\Core\Support\Packages\RegistersInstalledRuntime;
use Capell\Core\Support\Runtime\RuntimeRoleResolver;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use Spatie\LaravelPackageTools\Package;

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

it('leaves failed activation retryable without swallowing its exception', function (): void {
    $provider = app()->register(RuntimeLifecycleFixture::class);
    $provider->fail = true;
    CapellCore::markPackageInstalled(RuntimeLifecycleFixture::$packageName);

    expect(fn () => resolve(InstalledRuntimeLifecycle::class)->refresh())->toThrow(RuntimeException::class, 'fixture failure');
    $provider->fail = false;
    resolve(InstalledRuntimeLifecycle::class)->refresh();
    resolve(InstalledRuntimeLifecycle::class)->refresh();

    expect($provider->registrations)->toBe(['runtime']);
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

class RuntimeLifecycleFixture extends AbstractPackageServiceProvider
{
    public static string $name = 'lifecycle-fixture';

    public static string $packageName = 'test/lifecycle-fixture';

    /** @var list<string> */
    public array $registrations = [];

    public bool $fail = false;

    #[Override]
    public function configurePackage(Package $package): void
    {
        $package->name(static::$name);
    }

    #[Override]
    protected function registerPackageMetadata(): static
    {
        return $this;
    }

    // Models an author moving the legacy body to the new hook. Opting in
    // must prevent the old callback from executing the same body again.
    #[Override]
    protected function bootInstalledPackage(): self
    {
        $this->bootInstalledRuntime();

        return $this;
    }

    #[Override]
    protected function bootInstalledRuntime(): void
    {
        throw_if($this->fail, RuntimeException::class, 'fixture failure');

        $this->registrations[] = 'runtime';
    }
}

final class BootCallbackRuntimeFixture extends RuntimeLifecycleFixture
{
    public static string $packageName = 'test/boot-callback-fixture';

    #[Override]
    public function packageBooted(): void
    {
        $this->app->booted(function (): void {
            if ($this->isPackageInstalled()) {
                // An ordinary application callback is deliberately not replayed.
                $this->registrations[] = 'legacy-application-callback';
            }
        });
    }

    #[Override]
    protected function bootInstalledPackage(): self
    {
        return $this;
    }
}

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

final class LegacyEnableRuntimeFixture extends AbstractPackageServiceProvider
{
    public static string $name = 'legacy-enable-runtime';

    public static string $packageName = 'test/legacy-enable-runtime';

    public int $calls = 0;

    #[Override]
    public function configurePackage(Package $package): void
    {
        $package->name(self::$name);
    }

    #[Override]
    protected function registerPackageMetadata(): static
    {
        return $this;
    }

    #[Override]
    protected function bootInstalledPackage(): self
    {
        $this->calls++;

        return $this;
    }
}

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

final class OrdinaryRuntimeChildFixture extends ServiceProvider
{
    use RegistersInstalledRuntime;

    public int $calls = 0;

    #[Override]
    public function register(): void
    {
        $this->registerInstalledRuntime(RuntimeLifecycleFixture::$packageName, 'admin');
    }

    protected function bootInstalledRuntime(): void
    {
        $this->calls++;
    }
}

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
