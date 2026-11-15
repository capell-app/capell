<?php

declare(strict_types=1);

use Capell\Frontend\Actions\IntegrateViteInputsAction;
use Capell\Frontend\Actions\PrepareFrontendInstallationAction;
use Capell\Frontend\Actions\WriteViteInputManifestAction;
use Capell\Frontend\Support\Assets\FrontendVitePluginRegistry;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

const VITE_HELPER_IMPORT = "import { capellViteInputs } from './vendor/capell-app/frontend/resources/js/capell-vite-inputs.js';";

beforeEach(function (): void {
    $this->viteConfigPath = base_path('vite.config.js');
    $this->originalViteConfig = File::exists($this->viteConfigPath) ? File::get($this->viteConfigPath) : null;
    $this->manifestPath = base_path('bootstrap/cache/capell-vite-inputs.json');
    $this->originalManifest = File::exists($this->manifestPath) ? File::get($this->manifestPath) : null;
});

afterEach(function (): void {
    foreach ([$this->viteConfigPath => $this->originalViteConfig, $this->manifestPath => $this->originalManifest] as $path => $original) {
        $original === null ? File::delete($path) : File::put($path, $original);
    }
});

function viteConfigWithPlugins(bool $inputsIntegrated = false): string
{
    $config = <<<'JS'
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
            ],
        }),
    ],
});
JS;

    return $inputsIntegrated
        ? VITE_HELPER_IMPORT . PHP_EOL . str_replace("input: [\n", "input: [\n                ...capellViteInputs(),\n", $config)
        : $config;
}

it('accepts npm package names only and lists each plugin once', function (): void {
    $registry = new FrontendVitePluginRegistry;
    $registry->register('@vitejs/plugin-vue', 'capell-app/inertia-vue-adapter');
    $registry->register('@vitejs/plugin-vue', 'capell-app/another-package');
    $registry->register('vite-plugin-example', 'capell-app/another-package');

    expect($registry->all())->toBe(['@vitejs/plugin-vue', 'vite-plugin-example'])
        ->and(fn () => $registry->register('../plugin', 'capell-app/x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register('vue()', 'capell-app/x'))->toThrow(InvalidArgumentException::class);
});

it('writes registered plugins into the Vite manifest and keeps the inputs-only shape without them', function (): void {
    WriteViteInputManifestAction::run([]);
    expect(json_decode(File::get($this->manifestPath), true, flags: JSON_THROW_ON_ERROR))->not->toHaveKey('plugins');

    resolve(FrontendVitePluginRegistry::class)->register('@vitejs/plugin-vue', 'capell-app/inertia-vue-adapter');
    WriteViteInputManifestAction::run([]);

    expect(json_decode(File::get($this->manifestPath), true, flags: JSON_THROW_ON_ERROR)['plugins'])->toBe(['@vitejs/plugin-vue']);
});

it('adds the plugin helper to a new configuration and to one integrated before plugins existed', function (bool $inputsIntegrated): void {
    File::put($this->viteConfigPath, viteConfigWithPlugins($inputsIntegrated));

    IntegrateViteInputsAction::run();
    IntegrateViteInputsAction::run();
    $config = File::get($this->viteConfigPath);

    expect($config)->toContain("import { capellViteInputs, capellVitePlugins } from './vendor/capell-app/frontend/resources/js/capell-vite-inputs.js';")
        ->and(substr_count($config, 'capellVitePlugins(),'))->toBe(1)
        ->and($config)->toContain('plugins: [capellVitePlugins(), ')
        ->and(substr_count($config, '...capellViteInputs()'))->toBe(1);
})->with([
    'new configuration' => [false],
    'inputs already integrated' => [true],
]);

it('leaves a configuration without a plugins array alone unless a package needs a plugin', function (): void {
    $config = "export default { input: ['resources/js/app.js'] };\n";
    File::put($this->viteConfigPath, $config);

    IntegrateViteInputsAction::run();
    expect(File::get($this->viteConfigPath))->not->toContain('capellVitePlugins');

    File::put($this->viteConfigPath, $config);
    resolve(FrontendVitePluginRegistry::class)->register('@vitejs/plugin-vue', 'capell-app/inertia-vue-adapter');

    expect(fn (): mixed => IntegrateViteInputsAction::run())->toThrow(RuntimeException::class, 'add capellVitePlugins() to your Vite plugins');
});

it('reports the plugin helper as missing only when a package needs a plugin', function (): void {
    File::put($this->viteConfigPath, viteConfigWithPlugins(inputsIntegrated: true));
    $prepare = resolve(PrepareFrontendInstallationAction::class);

    expect($prepare->vitePluginHelperIsIntegrated())->toBeTrue();

    resolve(FrontendVitePluginRegistry::class)->register('@vitejs/plugin-vue', 'capell-app/inertia-vue-adapter');
    expect($prepare->vitePluginHelperIsIntegrated())->toBeFalse();

    IntegrateViteInputsAction::run();
    expect($prepare->vitePluginHelperIsIntegrated())->toBeTrue();
});

it('loads each registered plugin from the application node_modules, including ESM-only packages', function (): void {
    $root = storage_path('framework/testing/vite-plugins-' . bin2hex(random_bytes(6)));

    try {
        File::ensureDirectoryExists($root . '/bootstrap/cache');
        File::put($root . '/package.json', '{"name":"app","private":true}');
        File::put($root . '/bootstrap/cache/capell-vite-inputs.json', '{"inputs":["resources/js/app.js"],"plugins":["plain-plugin","@scope/esm-only-plugin"]}');
        File::ensureDirectoryExists($root . '/node_modules/plain-plugin');
        File::put($root . '/node_modules/plain-plugin/package.json', '{"name":"plain-plugin","type":"module","exports":"./index.js"}');
        File::put($root . '/node_modules/plain-plugin/index.js', "export default () => ({ name: 'plain' });\n");
        File::ensureDirectoryExists($root . '/node_modules/@scope/esm-only-plugin/dist');
        File::put($root . '/node_modules/@scope/esm-only-plugin/package.json', '{"name":"@scope/esm-only-plugin","type":"module","exports":{".":{"import":"./dist/index.js"}}}');
        File::put($root . '/node_modules/@scope/esm-only-plugin/dist/index.js', "export default () => ({ name: 'esm-only' });\n");

        $helper = realpath(__DIR__ . '/../../../resources/js/capell-vite-inputs.js');
        $script = sprintf(
            'import { capellViteInputs, capellVitePlugins } from %s; const plugins = await capellVitePlugins(%s); console.log(JSON.stringify({ inputs: capellViteInputs(%s), plugins: plugins.map((plugin) => plugin.name) }));',
            json_encode('file://' . $helper),
            json_encode($root),
            json_encode($root),
        );
        $process = new Process(['node', '--input-type=module', '-e', $script]);
        $process->setTimeout(120);
        $process->mustRun();

        expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'inputs' => ['resources/js/app.js'],
            'plugins' => ['plain', 'esm-only'],
        ]);
    } finally {
        File::deleteDirectory($root);
    }
});
