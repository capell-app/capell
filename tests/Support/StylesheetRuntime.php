<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

final class StylesheetRuntime
{
    /** @param list<string> $candidates
     * @param  array<string, string>  $stylesheets
     * @return array<string, mixed>
     */
    public static function inspect(string $path, bool $tailwind = false, array $candidates = [], array $stylesheets = []): array
    {
        $root = dirname(__DIR__, 2);

        if (! is_dir($root . '/node_modules/lightningcss') || ! is_dir($root . '/node_modules/tailwindcss')) {
            TestCase::markTestSkipped('The stylesheet compilers are not installed; run npm ci to inspect compiled stylesheets.');
        }

        $process = new Process(['node', $root . '/tests/Support/stylesheet-runtime.cjs', $path, $tailwind ? 'tailwind' : 'css', json_encode($candidates, JSON_THROW_ON_ERROR), json_encode($stylesheets, JSON_THROW_ON_ERROR)], $root);
        $process->setTimeout(30);
        $process->mustRun();
        /** @var array<string, mixed> $result */
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $result;
    }

    /** @return array<string, mixed> */
    public static function declarations(string $css): array
    {
        $path = tempnam(sys_get_temp_dir(), 'capell-css-declarations-');
        throw_if($path === false, RuntimeException::class, 'Cannot create a stylesheet fixture.');

        try {
            file_put_contents($path, $css);

            return self::inspect($path);
        } finally {
            unlink($path);
        }
    }
}
