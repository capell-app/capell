<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

final class PatchedPhpRuntime
{
    /** @return array<string, mixed> */
    public static function evaluate(string $path, string $expression, string $bootstrap = ''): array
    {
        $root = dirname(__DIR__, 2);
        $program = 'require $argv[1] . "/vendor/autoload.php"; ' . $bootstrap
            . ' require $argv[2]; echo json_encode(' . $expression . ', JSON_THROW_ON_ERROR);';
        $process = new Process([PHP_BINARY, '-d', 'auto_prepend_file=', '-r', $program, $root, $path], $root);
        $process->setTimeout(30);
        $process->mustRun();

        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        throw_unless(is_array($result), RuntimeException::class, 'Patched PHP must return structured outcomes.');

        return $result;
    }
}
