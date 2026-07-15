<?php

declare(strict_types=1);

namespace Capell\Core\Support\Process;

final class ArtisanProcessEnvironment
{
    /**
     * @param  array<string, string>|null  $environment
     * @return array<string, string>|null
     */
    public static function prepare(?array $environment = null): ?array
    {
        $basePath = str_replace('\\', '/', base_path());

        if (! str_contains($basePath, 'testbench-skeletons')
            && ! str_contains($basePath, '/vendor/orchestra/testbench-core/laravel')) {
            return $environment;
        }

        $workingPath = function_exists('Orchestra\\Testbench\\package_path')
            ? \Orchestra\Testbench\package_path()
            : dirname(__DIR__, 5);

        return array_merge($environment ?? [], [
            'TESTBENCH_WORKING_PATH' => $workingPath,
        ]);
    }
}
