<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

/**
 * Points this process's Blade compiler at its own compiled-view directory.
 *
 * An isolated test (#[RunInSeparateProcess]) starts with its parent worker's
 * environment copied into $_SERVER and $_ENV, and Laravel's Env reads those
 * before getenv(). Setting putenv() alone left the child compiling into the
 * parent's directory, so a view the child compiled differently (for example a
 * Blaze-wrapped component) replaced the parent's compiled file and later
 * renders in that worker used it.
 */
final class WorkerCompiledViewPath
{
    public const string VARIABLE = 'VIEW_COMPILED_PATH';

    public static function apply(string $path): void
    {
        putenv(self::VARIABLE . '=' . $path);
        $_SERVER[self::VARIABLE] = $path;
        $_ENV[self::VARIABLE] = $path;
    }
}
