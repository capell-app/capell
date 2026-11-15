<?php

declare(strict_types=1);

use Capell\Frontend\Actions\IntegrateViteInputsAction;
use Capell\Frontend\Actions\PrepareFrontendInstallationAction;
use Capell\Frontend\Actions\WriteViteInputManifestAction;
use Capell\Frontend\Exceptions\FrontendResourcePlanException;
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

it('accepts npm package names only and lists each plugin once with its options', function (): void {
    $registry = new FrontendVitePluginRegistry;
    $registry->register('@vitejs/plugin-vue', 'capell-app/inertia-vue-adapter', ['template' => ['transformAssetUrls' => ['base' => null]]]);
    $registry->register('@vitejs/plugin-vue', 'capell-app/another-package', ['template' => ['transformAssetUrls' => ['base' => null]]]);
    $registry->register('vite-plugin-example', 'capell-app/another-package');

    expect($registry->modules())->toBe(['@vitejs/plugin-vue', 'vite-plugin-example'])
        ->and($registry->all()[0]['options'])->toBe(['template' => ['transformAssetUrls' => ['base' => null]]])
        ->and(fn () => $registry->register('../plugin', 'capell-app/x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register('vue()', 'capell-app/x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register('@vitejs/plugin-vue', 'capell-app/x', ['template' => []]))->toThrow(InvalidArgumentException::class, 'different options')
        ->and(fn () => $registry->register('vite-plugin-list', 'capell-app/x', ['a', 'b']))->toThrow(InvalidArgumentException::class, 'named options');

    $registry->register('vite-plugin-keyed', 'capell-app/first', ['alpha' => 1, 'beta' => ['x' => 1, 'y' => 2]]);
    $registry->register('vite-plugin-keyed', 'capell-app/second', ['beta' => ['y' => 2, 'x' => 1], 'alpha' => 1]);

    expect($registry->modules())->toContain('vite-plugin-keyed');
});

it('writes registered plugins into the Vite manifest and keeps the inputs-only shape without them', function (): void {
    WriteViteInputManifestAction::run([]);
    expect(json_decode(File::get($this->manifestPath), true, flags: JSON_THROW_ON_ERROR))->not->toHaveKey('plugins');

    resolve(FrontendVitePluginRegistry::class)->register('@vitejs/plugin-vue', 'capell-app/inertia-vue-adapter');
    resolve(FrontendVitePluginRegistry::class)->register('vite-plugin-example', 'capell-app/another-package', ['mode' => 'strict']);
    WriteViteInputManifestAction::run([]);

    // Empty options stay a JSON object, so the factory always receives an options object.
    expect(File::get($this->manifestPath))->toContain('"options": {}')
        ->and(json_decode(File::get($this->manifestPath), true, flags: JSON_THROW_ON_ERROR)['plugins'])->toBe([
            ['module' => '@vitejs/plugin-vue', 'options' => []],
            ['module' => 'vite-plugin-example', 'options' => ['mode' => 'strict']],
        ]);
});

it('adds the plugin helper to a new configuration and to one integrated before plugins existed', function (bool $inputsIntegrated): void {
    File::put($this->viteConfigPath, viteConfigWithPlugins($inputsIntegrated));
    resolve(FrontendVitePluginRegistry::class)->register('@vitejs/plugin-vue', 'capell-app/inertia-vue-adapter');

    IntegrateViteInputsAction::run();
    IntegrateViteInputsAction::run();
    $config = File::get($this->viteConfigPath);

    expect($config)->toContain("import { capellViteInputs, capellVitePlugins } from './vendor/capell-app/frontend/resources/js/capell-vite-inputs.js';")
        ->and(substr_count($config, 'capellVitePlugins(),'))->toBe(1)
        ->and($config)->toContain("plugins: [\n        capellVitePlugins(),\n        laravel(")
        ->and($config)->not->toMatch('/[ \t]$/m')
        ->and(substr_count($config, '...capellViteInputs()'))->toBe(1);
})->with([
    'new configuration' => [false],
    'inputs already integrated' => [true],
]);

it('never edits the plugins of an application whose packages need no Vite plugin', function (): void {
    File::put($this->viteConfigPath, viteConfigWithPlugins(inputsIntegrated: true));

    IntegrateViteInputsAction::run();

    expect(File::get($this->viteConfigPath))->toBe(viteConfigWithPlugins(inputsIntegrated: true));
});

it('edits only the exported configuration plugins array, never nested, commented or quoted ones', function (): void {
    $config = VITE_HELPER_IMPORT . PHP_EOL . <<<'JS'
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

// plugins: [ was the old layout
const note = 'plugins: [';

export default defineConfig({
    css: { postcss: { plugins: [autoprefixer()] } },
    build: { rollupOptions: { plugins: [visualizer()] } },
    plugins: [
        laravel({ input: [...capellViteInputs(), 'resources/js/app.js'] }),
    ],
});
JS;
    File::put($this->viteConfigPath, $config);
    resolve(FrontendVitePluginRegistry::class)->register('@vitejs/plugin-vue', 'capell-app/inertia-vue-adapter');

    IntegrateViteInputsAction::run();
    $updated = File::get($this->viteConfigPath);

    expect($updated)->toContain("    plugins: [\n        capellVitePlugins(),\n        laravel(")
        ->and($updated)->not->toMatch('/[ \t]$/m')
        ->and($updated)->toContain('postcss: { plugins: [autoprefixer()] }')
        ->and($updated)->toContain('rollupOptions: { plugins: [visualizer()] }')
        ->and($updated)->toContain("// plugins: [ was the old layout\nconst note = 'plugins: [';")
        ->and(substr_count($updated, 'capellVitePlugins()'))->toBe(1);
});

it('asks for a manual edit when a package needs a plugin the configuration cannot take automatically', function (string $config): void {
    File::put($this->viteConfigPath, $config);
    resolve(FrontendVitePluginRegistry::class)->register('@vitejs/plugin-vue', 'capell-app/inertia-vue-adapter');

    expect(fn (): mixed => IntegrateViteInputsAction::run())->toThrow(RuntimeException::class, 'add capellVitePlugins() to the plugins array')
        ->and(File::get($this->viteConfigPath))->toBe($config);
})->with([
    'no plugins array' => [VITE_HELPER_IMPORT . "\nexport default { input: [...capellViteInputs(), 'resources/js/app.js'] };\n"],
    'configuration function' => [VITE_HELPER_IMPORT . "\nexport default defineConfig(({ mode }) => ({ plugins: [laravel({ input: [...capellViteInputs(), 'resources/js/app.js'] })] }));\n"],
    'plugins only in a later object' => [VITE_HELPER_IMPORT . "\nexport default defineConfig({ build: { outDir: 'public/build' } });\nconst other = { plugins: [legacy()] };\n"],
    'only a commented helper' => [VITE_HELPER_IMPORT . "\n// capellVitePlugins()\nexport default { input: [...capellViteInputs(), 'resources/js/app.js'] };\n"],
]);

it('leaves package.json untouched when the plugin helper cannot be added during apply', function (): void {
    $packageJsonPath = base_path('package.json');
    $originalPackageJson = File::exists($packageJsonPath) ? File::get($packageJsonPath) : null;
    File::put($packageJsonPath, '{"private":true}');
    File::put($this->viteConfigPath, VITE_HELPER_IMPORT . "\nexport default { input: [...capellViteInputs(), 'resources/js/app.js'] };\n");
    resolve(FrontendVitePluginRegistry::class)->register('@vitejs/plugin-vue', 'capell-app/inertia-vue-adapter');

    try {
        expect(fn (): mixed => PrepareFrontendInstallationAction::run())->toThrow(FrontendResourcePlanException::class)
            ->and(File::get($packageJsonPath))->toBe('{"private":true}');
    } finally {
        $originalPackageJson === null ? File::delete($packageJsonPath) : File::put($packageJsonPath, $originalPackageJson);
    }
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

it('loads each registered plugin from the application node_modules with its options, whatever the package format', function (): void {
    $root = storage_path('framework/testing/vite-plugins-' . bin2hex(random_bytes(6)));

    try {
        File::ensureDirectoryExists($root . '/bootstrap/cache');
        File::put($root . '/package.json', '{"name":"app","private":true}');
        File::put($root . '/bootstrap/cache/capell-vite-inputs.json', '{"inputs":["resources/js/app.js"],"plugins":[{"module":"plain-plugin","options":{"mode":"strict"}},{"module":"@scope/esm-only-plugin","options":{}},{"module":"nested-conditions-plugin","options":{}},{"module":"cjs-default-plugin","options":{}}]}');
        File::ensureDirectoryExists($root . '/node_modules/nested-conditions-plugin/dist');
        File::put($root . '/node_modules/nested-conditions-plugin/package.json', '{"name":"nested-conditions-plugin","type":"module","exports":{".":{"import":{"types":"./dist/index.d.ts","default":"./dist/index.js"}}}}');
        File::put($root . '/node_modules/nested-conditions-plugin/dist/index.js', "export default () => ({ name: 'nested' });\n");
        File::ensureDirectoryExists($root . '/node_modules/cjs-default-plugin');
        File::put($root . '/node_modules/cjs-default-plugin/package.json', '{"name":"cjs-default-plugin","main":"index.cjs"}');
        File::put($root . '/node_modules/cjs-default-plugin/index.cjs', "exports.default = () => ({ name: 'cjs-default' });\n");
        File::ensureDirectoryExists($root . '/node_modules/plain-plugin');
        File::put($root . '/node_modules/plain-plugin/package.json', '{"name":"plain-plugin","type":"module","exports":"./index.js"}');
        File::put($root . '/node_modules/plain-plugin/index.js', "export default (options) => ({ name: 'plain:' + options.mode });\n");
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
            'plugins' => ['plain:strict', 'esm-only', 'nested', 'cjs-default'],
        ]);
    } finally {
        File::deleteDirectory($root);
    }
});

it('keeps symlinked package paths when Capell packages are installed from path repositories', function (bool $symlinked): void {
    $root = storage_path('framework/testing/vite-path-repositories-' . bin2hex(random_bytes(6)));
    $packageSource = $root . '-package-source';

    try {
        File::ensureDirectoryExists($root . '/bootstrap/cache');
        File::ensureDirectoryExists($root . '/vendor/capell-app');
        File::ensureDirectoryExists($packageSource);
        File::put($root . '/package.json', '{"name":"app","private":true}');
        File::put($root . '/bootstrap/cache/capell-vite-inputs.json', '{"inputs":["resources/js/app.js"],"plugins":[]}');

        if ($symlinked) {
            symlink($packageSource, $root . '/vendor/capell-app/frontend');
        } else {
            File::ensureDirectoryExists($root . '/vendor/capell-app/frontend');
        }

        $helper = realpath(__DIR__ . '/../../../resources/js/capell-vite-inputs.js');
        $script = sprintf(
            'import { capellVitePlugins } from %s; const plugins = await capellVitePlugins(%s); console.log(JSON.stringify(plugins.map((plugin) => ({ name: plugin.name, config: plugin.config ? plugin.config() : null }))));',
            json_encode('file://' . $helper),
            json_encode($root),
        );
        $process = new Process(['node', '--input-type=module', '-e', $script]);
        $process->setTimeout(120);
        $process->mustRun();

        expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe($symlinked
            ? [['name' => 'capell:path-repository-packages', 'config' => ['resolve' => ['preserveSymlinks' => true]]]]
            : []);
    } finally {
        if (is_link($root . '/vendor/capell-app/frontend')) {
            unlink($root . '/vendor/capell-app/frontend');
        }

        File::deleteDirectory($root);
        File::deleteDirectory($packageSource);
    }
})->with([
    'path repository symlink' => [true],
    'installed package directory' => [false],
]);

it('adds the plugin helper inline to a plugins array written on one line', function (): void {
    File::put($this->viteConfigPath, VITE_HELPER_IMPORT . PHP_EOL . <<<'JS'
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [laravel({ input: [...capellViteInputs(), 'resources/js/app.js'] })],
});
JS);
    resolve(FrontendVitePluginRegistry::class)->register('@vitejs/plugin-vue', 'capell-app/inertia-vue-adapter');

    IntegrateViteInputsAction::run();

    expect(File::get($this->viteConfigPath))->toContain('plugins: [capellVitePlugins(), laravel(')
        ->not->toMatch('/[ \t]$/m');
});
