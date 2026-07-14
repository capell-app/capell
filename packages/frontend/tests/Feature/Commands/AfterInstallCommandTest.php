<?php

declare(strict_types=1);

use Capell\Frontend\Data\Assets\FrontendPackageDependencyData;
use Capell\Frontend\Enums\FrontendPackageDependencyType;
use Capell\Frontend\Support\Assets\FrontendPackageDependencyRegistry;
use Capell\Frontend\Support\Assets\FrontendViteInputRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
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
    Process::assertRan(fn ($process): bool => $process->command === ['npm', 'install', 'swiper@^12.0.0']);
    Process::assertRan(fn ($process): bool => $process->command === ['npm', 'install', '--save-dev', 'vite-plugin-example@^2.0.0']);
    Process::assertRan(fn ($process): bool => $process->command === ['npm', 'run', 'build']);
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
