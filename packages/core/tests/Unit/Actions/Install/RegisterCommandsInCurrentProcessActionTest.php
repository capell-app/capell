<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\RegisterCommandsInCurrentProcessAction;
use Capell\Core\Actions\Install\RegisterProviderCommandsInCurrentProcessAction;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\InstallStepExecutor;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageLoader;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Capell\Core\Tests\Support\Install\FailingLateInstallTestProvider;
use Capell\Core\Tests\Support\Install\LateInstallTestCommand;
use Capell\Core\Tests\Support\Install\LateInstallTestProvider;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Exception\CommandNotFoundException;

it('reproduces a package command missing after its provider is added to a booted console', function (): void {
    Artisan::all();
    app()->register(LateInstallTestProvider::class);

    expect(fn (): int => Artisan::call('test:late-package-install'))->toThrow(CommandNotFoundException::class);

    RegisterCommandsInCurrentProcessAction::run([LateInstallTestCommand::class]);

    expect(Artisan::call('test:late-package-install', ['--value' => 'same process']))->toBe(0)
        ->and(trim(Artisan::output()))->toBe('same process');
});

it('registers late provider commands before the installer calls them in the same process', function (): void {
    Artisan::all();

    RegisterProviderCommandsInCurrentProcessAction::run(function (): void {
        app()->register(LateInstallTestProvider::class);
    });

    expect(Artisan::call('test:late-package-install', ['--value' => 'installed']))->toBe(0)
        ->and(trim(Artisan::output()))->toBe('installed');
});

it('keeps the registered instance when classes or instances are registered repeatedly', function (): void {
    $command = new LateInstallTestCommand;
    $command->setAliases(['test:late-alias']);
    RegisterCommandsInCurrentProcessAction::run([$command, LateInstallTestCommand::class, new LateInstallTestCommand]);
    RegisterCommandsInCurrentProcessAction::run([LateInstallTestCommand::class]);

    expect(Artisan::all()['test:late-package-install'])->toBe($command)
        ->and(Artisan::all()['test:late-alias'])->toBe($command)
        ->and(Artisan::call('test:late-alias'))->toBe(0);
});

it('registers commands when the console kernel has not built its application yet', function (): void {
    RegisterCommandsInCurrentProcessAction::run([LateInstallTestCommand::class]);

    expect(Artisan::call('test:late-package-install'))->toBe(0);
});

it('makes commands from a package discovered mid-install callable through the real loader', function (): void {
    Artisan::all();
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'vendor/late-install' => CapellManifestData::fromArray(capellManifestV3Array(
            name: 'vendor/late-install',
            providers: ['install' => [LateInstallTestProvider::class]],
        )),
    ]);

    new CapellPackageLoader(app(), $registry)->loadProviders();

    expect(Artisan::call('test:late-package-install'))->toBe(0);
});

it('does not replay unrelated console starting callbacks during late registration', function (): void {
    Artisan::all();
    $calls = 0;
    $return = RegisterProviderCommandsInCurrentProcessAction::run(function () use (&$calls): string {
        ConsoleApplication::starting(function () use (&$calls): void {
            $calls++;
        });
        app()->register(LateInstallTestProvider::class);

        return 'provider loaded';
    });

    expect($calls)->toBe(0)->and($return)->toBe('provider loaded')
        ->and(Artisan::call('test:late-package-install'))->toBe(0);
});

it('leaves commands from a quarantined provider unregistered in the running console', function (): void {
    Artisan::all();
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'vendor/failing-late-install' => CapellManifestData::fromArray(capellManifestV3Array(
            name: 'vendor/failing-late-install',
            providers: ['install' => [FailingLateInstallTestProvider::class]],
        )),
    ]);

    new CapellPackageLoader(app(), $registry)->loadProviders();

    expect(fn (): int => Artisan::call('test:late-package-install'))->toThrow(CommandNotFoundException::class);
});

it('registers commands declared by an installed runtime activation hook', function (): void {
    Artisan::all();
    CapellCore::registerPackage('vendor/late-runtime-commands');
    CapellCore::markPackageInstalled('vendor/late-runtime-commands');
    $provider = new LateInstallTestProvider(app());
    $lifecycle = resolve(InstalledRuntimeLifecycle::class);
    $lifecycle->register(LateInstallTestProvider::class, 'vendor/late-runtime-commands', 'runtime', function () use ($provider): void {
        $provider->commands([LateInstallTestCommand::class]);
    });
    $lifecycle->refresh('vendor/late-runtime-commands');

    expect(Artisan::call('test:late-package-install'))->toBe(0);
});

it('leaves late provider declarations for normal startup when the console application is not built', function (): void {
    $kernel = Artisan::getFacadeRoot();
    $application = new ReflectionProperty(ConsoleKernel::class, 'artisan');
    expect($application->getValue($kernel))->toBeNull();

    RegisterProviderCommandsInCurrentProcessAction::run(function (): void {
        app()->register(LateInstallTestProvider::class);
    });

    expect($application->getValue($kernel))->toBeNull()
        ->and(Artisan::call('test:late-package-install'))->toBe(0);
});

it('does not resolve the console kernel when loading providers that declare no commands', function (): void {
    $resolutions = 0;
    app()->forgetInstance(ConsoleKernelContract::class);
    Facade::clearResolvedInstance(ConsoleKernelContract::class);
    app()->singleton(ConsoleKernelContract::class, function () use (&$resolutions): ConsoleKernel {
        $resolutions++;

        return new ConsoleKernel(app(), resolve(Dispatcher::class));
    });

    $result = RegisterProviderCommandsInCurrentProcessAction::run(fn (): string => 'loaded without commands');

    expect($result)->toBe('loaded without commands')->and($resolutions)->toBe(0);
});

it('makes commands from providers Composer discovered during the installer run callable', function (): void {
    Artisan::all();
    $basePath = app()->basePath();
    $temporaryPath = sys_get_temp_dir() . '/capell-composer-providers-' . bin2hex(random_bytes(4));
    mkdir($temporaryPath . '/bootstrap/cache', 0777, true);
    file_put_contents(
        $temporaryPath . '/bootstrap/cache/packages.php',
        '<?php return ' . var_export(['vendor/composer-late' => ['providers' => [LateInstallTestProvider::class]]], true) . ';',
    );
    app()->setBasePath($temporaryPath);

    try {
        $executor = resolve(InstallStepExecutor::class);
        new ReflectionMethod($executor, 'registerComposerDiscoveredProviders')->invoke($executor);
    } finally {
        app()->setBasePath($basePath);
        File::deleteDirectory($temporaryPath);
    }

    expect(Artisan::call('test:late-package-install'))->toBe(0);
});
