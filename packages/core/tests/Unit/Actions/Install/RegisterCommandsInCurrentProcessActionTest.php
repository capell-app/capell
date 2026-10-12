<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\RegisterCommandsInCurrentProcessAction;
use Capell\Core\Actions\Install\RegisterProviderCommandsInCurrentProcessAction;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageLoader;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Command;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Console\Exception\CommandNotFoundException;

final class LateInstallTestCommand extends Command
{
    protected $signature = 'test:late-package-install {--value=}';

    public function handle(): int
    {
        $value = $this->option('value');
        $this->line(is_string($value) ? $value : '');

        return self::SUCCESS;
    }
}

final class LateInstallTestProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->commands([LateInstallTestCommand::class]);
    }
}

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

final class FailingLateInstallTestProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->commands([LateInstallTestCommand::class]);

        throw new RuntimeException('Late provider failed.');
    }
}

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
