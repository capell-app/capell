<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use Symfony\Component\Process\Process;

final class TailwindFixture
{
    public static function build(string $path): string
    {
        $runner = <<<'JS'
const fs = require('node:fs');
const path = require('node:path');
const {compile} = require('@tailwindcss/node');
const {Scanner} = require('@tailwindcss/oxide');
(async () => {
    const stylesheet = process.argv[1];
    const compiler = await compile(fs.readFileSync(stylesheet, 'utf8'), {
        base: path.dirname(stylesheet),
        onDependency() {},
        customCssResolver: id => {
            if (id === 'tailwindcss') return require.resolve('tailwindcss/index.css');
            if (id.endsWith('/vendor/filament/filament/resources/css/theme.css')) return path.join(process.cwd(), 'vendor/filament/filament/resources/css/theme.css');
            return undefined;
        },
    });
    const scanner = new Scanner({sources: compiler.sources});
    process.stdout.write(compiler.build(scanner.scan()));
})().catch(error => {console.error(error); process.exitCode = 1;});
JS;
        $process = new Process(['node', '-e', $runner, $path], dirname(__DIR__, 2));
        $process->mustRun();

        return $process->getOutput();
    }
}
