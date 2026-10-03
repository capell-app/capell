<?php

declare(strict_types=1);

use Capell\Core\Actions\AfterInstallPackageAction;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Core\Support\Install\InstallRunState;
use Capell\Core\Support\Install\InstallStepExecutor;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Frontend\Actions\IntegrateViteInputsAction;
use Capell\Frontend\Actions\PrepareFrontendInstallationAction;
use Capell\Frontend\Actions\PrepareFrontendPackageAction;
use Capell\Frontend\Data\Assets\FrontendPackageDependencyData;
use Capell\Frontend\Enums\FrontendPackageDependencyType;
use Capell\Frontend\Exceptions\FrontendResourcePlanException;
use Capell\Frontend\Providers\FrontendServiceProvider;
use Capell\Frontend\Support\Assets\FrontendPackageDependencyRegistry;
use Capell\Frontend\Support\Assets\FrontendViteInputRegistry;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->packageJsonPath = base_path('package.json');
    $this->originalPackageJson = File::exists($this->packageJsonPath) ? File::get($this->packageJsonPath) : null;
    File::put($this->packageJsonPath, json_encode(['private' => true, 'scripts' => ['build' => 'vite build']], JSON_THROW_ON_ERROR));
    $this->viteConfigPath = base_path('vite.config.js');
    $this->originalViteConfig = File::exists($this->viteConfigPath) ? File::get($this->viteConfigPath) : null;
    $this->generatedAsset = base_path('resources/css/capell-test/frontend.css');
    $this->generatedManifest = base_path('bootstrap/cache/capell-vite-inputs.json');
    File::delete($this->generatedManifest);
    app()->instance(FrontendPackageDependencyRegistry::class, new FrontendPackageDependencyRegistry);
    app()->instance(FrontendViteInputRegistry::class, new FrontendViteInputRegistry);
    bindFrontendPlanGenerator($this->generatedAsset);
});

afterEach(function (): void {
    if ($this->originalPackageJson === null) {
        File::delete($this->packageJsonPath);
    } else {
        File::put($this->packageJsonPath, $this->originalPackageJson);
    }

    if ($this->originalViteConfig === null) {
        File::delete($this->viteConfigPath);
    } else {
        File::put($this->viteConfigPath, $this->originalViteConfig);
    }

    File::deleteDirectory(dirname($this->generatedAsset));
    File::delete($this->generatedManifest);
});

it('prints a deterministic report and makes no changes non-interactively without apply', function (): void {
    File::put($this->viteConfigPath, viteConfigWithCapellInputs());
    Process::fake();

    $exit = Artisan::call('capell:frontend-after-install', ['--no-interaction' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Frontend installation plan:')
        ->and($output)->toContain('- Package manager: npm')
        ->and($output)->toContain('- Generated Vite inputs: resources/css/capell/frontend.css')
        ->and($output)->toContain('- Build command: npm run build')
        ->and($output)->toContain('Report only')
        ->and(File::exists($this->generatedAsset))->toBeFalse()
        ->and(File::exists($this->generatedManifest))->toBeFalse();
    Process::assertNothingRan();
});

it('prints exact Vite remediation and refuses apply when integration is missing', function (): void {
    File::put($this->viteConfigPath, "export default { input: ['resources/js/app.js'] }");
    Process::fake();

    $exit = Artisan::call('capell:frontend-after-install', [
        '--no-interaction' => true,
        '--apply' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain("import { capellViteInputs } from './vendor/capell-app/frontend/resources/js/capell-vite-inputs.js'")
        ->and($output)->toContain('input: [...capellViteInputs(), /* application entries */]')
        ->and(File::get($this->viteConfigPath))->not->toContain('capellViteInputs');
    Process::assertNothingRan();
});

it('applies separate dependency commands generates the input manifest and builds when explicitly requested', function (): void {
    File::put($this->viteConfigPath, viteConfigWithCapellInputs());
    $registry = resolve(FrontendPackageDependencyRegistry::class);
    $registry->register(new FrontendPackageDependencyData('swiper', '^12.0.0', FrontendPackageDependencyType::Runtime, 'capell-app/gallery'));
    $registry->register(new FrontendPackageDependencyData('vite-plugin-example', '^2.0.0', FrontendPackageDependencyType::Development, 'capell-app/gallery'));

    resolve(FrontendViteInputRegistry::class)->register('vendor/capell-app/gallery/resources/js/gallery.js', 'capell-app/gallery');
    Process::fake(fn () => Process::result(output: 'process output'));

    $exit = Artisan::call('capell:frontend-after-install', [
        '--no-interaction' => true,
        '--apply' => true,
    ]);

    expect($exit)->toBe(0)
        ->and(File::exists($this->generatedAsset))->toBeTrue()
        ->and(json_decode(File::get($this->generatedManifest), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'inputs' => [
                'resources/css/capell-test/frontend.css',
                'vendor/capell-app/gallery/resources/js/gallery.js',
            ],
        ]);
    Process::assertRan(fn ($process): bool => $process->command === ['npm', 'install', 'swiper@^12.0.0']
        && $process->timeout === 900);
    Process::assertRan(fn ($process): bool => $process->command === ['npm', 'install', '--save-dev', 'vite-plugin-example@^2.0.0']
        && $process->timeout === 900);
    Process::assertRan(fn ($process): bool => $process->command === ['npm', 'run', 'build']
        && $process->timeout === 900);
});

it('requires interactive confirmation before applying the printed plan', function (): void {
    File::put($this->viteConfigPath, viteConfigWithCapellInputs());
    Process::fake();

    artisanCommand('capell:frontend-after-install')
        ->expectsConfirmation('Apply this frontend installation plan?', 'no')
        ->expectsOutputToContain('Frontend installation plan was not applied.')
        ->assertExitCode(0);

    expect(File::exists($this->generatedAsset))->toBeFalse();
    Process::assertNothingRan();
});

it('returns package-manager failure output unchanged and adds separate remediation', function (): void {
    File::put($this->viteConfigPath, viteConfigWithCapellInputs());
    resolve(FrontendPackageDependencyRegistry::class)->register(new FrontendPackageDependencyData('swiper', '^12.0.0', FrontendPackageDependencyType::Runtime, 'capell-app/gallery'));
    Process::fake(fn () => Process::result(output: "native stdout\n", errorOutput: "native stderr\n", exitCode: 1));

    $exit = Artisan::call('capell:frontend-after-install', [
        '--no-interaction' => true,
        '--apply' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain("native stdout\n")
        ->and($output)->toContain("native stderr\n")
        ->and($output)->toContain('Capell remediation:');
});

function bindFrontendPlanGenerator(string $assetPath): void
{
    app()->bind('capell.tailwind.generator', fn (): object => new readonly class($assetPath)
    {
        public function __construct(private string $assetPath) {}

        public function generate(?string $overridePath = null): array
        {
            $path = $overridePath ?? $this->assetPath;
            File::ensureDirectoryExists(dirname($path));
            File::put($path, '/* generated */');

            return [$path];
        }
    });
}

function viteConfigWithCapellInputs(): string
{
    return <<<'JS'
import { capellViteInputs } from './vendor/capell-app/frontend/resources/js/capell-vite-inputs.js'

export default { input: [...capellViteInputs(), 'resources/js/app.js'] }
JS;
}

it('executes the manifest frontend hook from the install plan without starting Node', function (): void {
    expect(new ReflectionClass(InstallPlan::class)->getFileName())->toBe(realpath(__DIR__ . '/../../../../core/src/Support/Install/InstallPlan.php'));
    expect(new ReflectionClass(FrontendServiceProvider::class)->getFileName())
        ->toBe(realpath(__DIR__ . '/../../../src/Providers/FrontendServiceProvider.php'));
    File::put($this->viteConfigPath, viteConfigWithCapellInputs());
    resolve(FrontendPackageDependencyRegistry::class)->register(new FrontendPackageDependencyData('swiper', '^12.0.0', FrontendPackageDependencyType::Runtime, 'capell-app/gallery'));
    resolve(FrontendViteInputRegistry::class)->register('vendor/capell-app/gallery/resources/js/gallery.js', 'capell-app/gallery');
    Process::fake();
    $package = new PackageData(
        name: 'capell-app/frontend',
        type: PackageTypeEnum::Package,
        installed: false,
        manifest: CapellManifestData::fromArray(json_decode(File::get(__DIR__ . '/../../../capell.json'), true, flags: JSON_THROW_ON_ERROR)),
    );
    CapellCore::partialMock()->shouldReceive('getPackages')->andReturn(collect([$package->name => $package]));
    CapellCore::shouldReceive('hasPackage')->with($package->name)->andReturn(true);
    CapellCore::shouldReceive('getPackage')->with($package->name)->andReturn($package);
    $input = new InstallInputData(siteUrl: 'https://example.test', packages: [$package->name], languages: ['en'], demoContent: false, cachesToClear: [], generateSitemap: false, generateStaticSite: false, rebuildResources: false);
    expect($package->getAfterInstallAction())->toBe(PrepareFrontendPackageAction::class);
    $key = InstallPlan::packageAfterInstallStepKey($package->name);
    expect(array_column(InstallPlan::build($input), 'key'))->toContain($key)->not->toContain(InstallPlan::STEP_REBUILD_RESOURCES);
    $state = new InstallRunState($input, new NullProgressReporter);
    $state->setResolvedUser(User::factory()->create());

    resolve(InstallStepExecutor::class)->execute($key, $state);
    AfterInstallPackageAction::run($package, allowLegacyCommand: false);
    expect(json_decode(File::get($this->packageJsonPath), true, flags: JSON_THROW_ON_ERROR)['dependencies'])->toBe(['swiper' => '^12.0.0'])
        ->and(File::exists($this->generatedAsset))->toBeTrue()
        ->and(File::get($this->generatedManifest))->toContain('gallery.js');
    Process::assertNothingRan();
});

it('prepares registered requirements idempotently while preserving application dependencies', function (): void {
    File::put($this->viteConfigPath, viteConfigWithCapellInputs());
    File::put($this->packageJsonPath, json_encode(['private' => true, 'devDependencies' => ['swiper' => '^12.1.0'], 'scripts' => ['build' => 'vite build']], JSON_THROW_ON_ERROR));
    resolve(FrontendPackageDependencyRegistry::class)->register(new FrontendPackageDependencyData('swiper', '^12.0.0', FrontendPackageDependencyType::Runtime, 'capell-app/gallery'));
    resolve(FrontendPackageDependencyRegistry::class)->register(new FrontendPackageDependencyData('vite-plugin-example', '^2.0.0', FrontendPackageDependencyType::Development, 'capell-app/gallery'));
    Process::fake();
    PrepareFrontendInstallationAction::run();
    $first = File::get($this->packageJsonPath);
    PrepareFrontendInstallationAction::run();
    expect(File::get($this->packageJsonPath))->toBe($first)
        ->and(json_decode($first, true, flags: JSON_THROW_ON_ERROR))->toBe(['private' => true, 'devDependencies' => ['swiper' => '^12.1.0', 'vite-plugin-example' => '^2.0.0'], 'scripts' => ['build' => 'vite build']]);
    Process::assertNothingRan();
});

it('propagates preparation failures through the typed browser lifecycle without Node or file changes', function (): void {
    File::put($this->viteConfigPath, 'export default {}');
    $before = File::get($this->packageJsonPath);
    Process::fake();
    $package = new PackageData(name: 'capell-app/frontend', type: PackageTypeEnum::Package, afterInstallAction: PrepareFrontendPackageAction::class);
    expect(fn () => AfterInstallPackageAction::run($package, allowLegacyCommand: false))->toThrow(FrontendResourcePlanException::class, 'Vite inputs are not integrated');
    expect(File::get($this->packageJsonPath))->toBe($before)->and(File::exists($this->generatedManifest))->toBeFalse();
    Process::assertNothingRan();
});

it('propagates an explicit production build failure and supports explicit development mode', function (): void {
    File::put($this->viteConfigPath, viteConfigWithCapellInputs());
    Process::fake(['*' => Process::result(errorOutput: 'vite compilation failed', exitCode: 1)]);
    expect(Artisan::call('capell:frontend-after-install', ['--no-interaction' => true, '--apply' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('vite compilation failed')->toContain('Capell remediation:');
    Process::fake();
    expect(Artisan::call('capell:frontend-after-install', ['--no-interaction' => true, '--apply' => true, '--dev' => true]))->toBe(0);
    Process::assertRan(fn ($process): bool => $process->command === ['npm', 'run', 'dev']);
});

it('preserves application JSON objects while preparing declared dependencies', function (): void {
    File::put($this->viteConfigPath, viteConfigWithCapellInputs());
    File::put($this->packageJsonPath, '{"private":true,"custom":{},"devDependencies":{}}');
    resolve(FrontendPackageDependencyRegistry::class)->register(new FrontendPackageDependencyData('swiper', '^12.0.0', FrontendPackageDependencyType::Runtime, 'capell-app/gallery'));
    PrepareFrontendInstallationAction::run();
    $package = json_decode(File::get($this->packageJsonPath), flags: JSON_THROW_ON_ERROR);
    expect($package->custom)->toBeInstanceOf(stdClass::class)->and($package->devDependencies)->toBeInstanceOf(stdClass::class);
});

it('propagates a failed host dependency manifest write before generating assets', function (): void {
    File::put($this->viteConfigPath, viteConfigWithCapellInputs());
    $files = Mockery::mock(Filesystem::class)->makePartial();
    $files->shouldReceive('put')->with($this->packageJsonPath, Mockery::type('string'))->once()->andReturn(false);
    $action = new PrepareFrontendInstallationAction($files);
    expect(function () use ($action): void {
        try {
            runBoundAction(PrepareFrontendInstallationAction::class, $action);
        } finally {
            expect(File::exists($this->generatedAsset))->toBeFalse();
        }
    })->toThrow(FrontendResourcePlanException::class, 'Unable to write');
});

it('prepares supported typed and ESM Vite configurations through the same discovery as integration', function (string $filename): void {
    File::delete($this->viteConfigPath);
    $path = base_path($filename);
    $before = File::exists($path) ? File::get($path) : null;
    File::put($path, viteConfigWithCapellInputs());
    Process::fake();
    try {
        expect(IntegrateViteInputsAction::run())->toBe($path);
        PrepareFrontendInstallationAction::run();
        expect(File::exists($this->generatedManifest))->toBeTrue();
        expect(Artisan::call('capell:frontend-after-install', ['--no-interaction' => true]))->toBe(0)
            ->and(Artisan::output())->toContain('Vite input integration: present');
        Process::assertNothingRan();
    } finally {
        if ($before === null) {
            File::delete($path);
        } else {
            File::put($path, $before);
        }
    }
})->with(['vite.config.ts', 'vite.config.mjs']);

it('propagates a real generated input write error through the Laravel exception gate', function (): void {
    File::put($this->viteConfigPath, viteConfigWithCapellInputs());
    File::ensureDirectoryExists($this->generatedManifest);
    Process::fake();
    try {
        expect(function (): void {
            PrepareFrontendInstallationAction::run();
        })->toThrow(ErrorException::class);
        Process::assertNothingRan();
    } finally {
        File::deleteDirectory($this->generatedManifest);
    }
});

it('prepares dependency groups for the declared manager without creating an npm lockfile', function (string $manager): void {
    File::put($this->viteConfigPath, viteConfigWithCapellInputs());
    File::put($this->packageJsonPath, json_encode(['packageManager' => $manager . '@1.0.0'], JSON_THROW_ON_ERROR));
    $npmLock = base_path('package-lock.json');
    $npmBefore = File::exists($npmLock) ? File::get($npmLock) : null;
    resolve(FrontendPackageDependencyRegistry::class)->register(new FrontendPackageDependencyData('swiper', '^12.0.0', FrontendPackageDependencyType::Runtime, 'capell-app/gallery'));
    resolve(FrontendPackageDependencyRegistry::class)->register(new FrontendPackageDependencyData('vite-plugin-example', '^2.0.0', FrontendPackageDependencyType::Development, 'capell-app/gallery'));
    Process::fake();
    PrepareFrontendInstallationAction::run();
    expect(json_decode(File::get($this->packageJsonPath), true, flags: JSON_THROW_ON_ERROR))->toBe(['packageManager' => $manager . '@1.0.0', 'dependencies' => ['swiper' => '^12.0.0'], 'devDependencies' => ['vite-plugin-example' => '^2.0.0']])
        ->and(File::exists($npmLock) ? File::get($npmLock) : null)->toBe($npmBefore);
    Process::assertNothingRan();
})->with(['pnpm', 'yarn', 'bun']);
