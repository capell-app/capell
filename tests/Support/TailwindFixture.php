<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use PHPUnit\Framework\SkippedWithMessageException;
use Symfony\Component\Process\Process;

final class TailwindFixture
{
    public static function build(string $path): string
    {
        $root = dirname(__DIR__, 2);

        throw_if(
            ! is_dir($root . '/node_modules/@tailwindcss/node') || ! is_dir($root . '/node_modules/@tailwindcss/oxide'),
            SkippedWithMessageException::class,
            'The Tailwind compiler is not installed; run npm ci to exercise generated stylesheets.',
        );

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
        $process = new Process(['node', '-e', $runner, $path], $root);
        $process->mustRun();

        return $process->getOutput();
    }
}
