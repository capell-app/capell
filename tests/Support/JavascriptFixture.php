<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

final class JavascriptFixture
{
    /** @param array<string, string> $modules */
    public static function evaluate(string $path, string $setup, string $expression, array $modules = []): mixed
    {
        $runner = <<<'JS_WRAP'
        const fs = require('node:fs');
        const vm = require('node:vm');
        const {path, setup, expression, modules} = JSON.parse(process.argv[1]);
        (async () => {
            const context = vm.createContext({console});
            vm.runInContext(setup, context);
            const entry = new vm.SourceTextModule(fs.readFileSync(path, 'utf8'), {context});
            await entry.link(async (specifier) => {
                if (!Object.hasOwn(modules, specifier)) throw new Error(`No local fixture for ${specifier}`);
                return new vm.SourceTextModule(modules[specifier], {context});
            });
            await entry.evaluate();
            context.result = entry.namespace.default;
            process.stdout.write(JSON.stringify(vm.runInContext(expression, context)));
        })().catch(error => {console.error(error); process.exitCode = 1;});
        JS_WRAP;
        $process = new Process(['node', '--experimental-vm-modules', '-e', $runner, json_encode(['path' => $path, 'setup' => $setup, 'expression' => $expression, 'modules' => $modules], JSON_THROW_ON_ERROR)], dirname(__DIR__, 2));
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $generatedInputs
     * @return list<string>
     */
    public static function viteInputs(string $path, array $generatedInputs = []): array
    {
        $inputs = self::evaluate($path, '', 'result.plugins ? result.plugins.flatMap(plugin => plugin.input ?? []) : result.input', [
            'vite' => 'export const defineConfig = value => value;',
            'laravel-vite-plugin' => 'export default value => value;',
            './vendor/capell-app/frontend/resources/js/capell-vite-inputs.js' => 'export const capellViteInputs = () => ' . json_encode($generatedInputs, JSON_THROW_ON_ERROR) . ';',
        ]);
        throw_if(! is_array($inputs) || ! array_is_list($inputs) || array_filter($inputs, is_string(...)) !== $inputs, RuntimeException::class, 'Generated Vite configuration must expose a list of input paths.');

        return $inputs;
    }
}
