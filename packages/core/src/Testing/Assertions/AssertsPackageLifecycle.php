<?php

declare(strict_types=1);

namespace Capell\Core\Testing\Assertions;

use AssertionError;
use Closure;
use Illuminate\Support\ServiceProvider;

final class AssertsPackageLifecycle
{
    /** @param list<string> $migrations */
    public static function run(string $packageRoot, ?string $providerClass, array $migrations, ?Closure $assertion): void
    {
        if ($providerClass !== null && (! class_exists($providerClass) || ! is_subclass_of($providerClass, ServiceProvider::class))) {
            throw new AssertionError("[provider.boot] {$packageRoot}: provider [{$providerClass}] is unavailable or invalid.");
        }

        foreach ($migrations as $migration) {
            if (! is_file($packageRoot . '/' . ltrim($migration, '/'))) {
                throw new AssertionError("[migration.discovery] {$packageRoot}: migration [{$migration}] is missing.");
            }
        }

        if ($assertion !== null && $assertion() !== true) {
            throw new AssertionError("[lifecycle.install-upgrade] {$packageRoot}: lifecycle assertion failed.");
        }
    }
}
