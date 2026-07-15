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
        throw_if($providerClass !== null && (! class_exists($providerClass) || ! is_subclass_of($providerClass, ServiceProvider::class)), AssertionError::class, sprintf('[provider.boot] %s: provider [%s] is unavailable or invalid.', $packageRoot, $providerClass));

        foreach ($migrations as $migration) {
            throw_unless(is_file($packageRoot . '/' . ltrim($migration, '/')), AssertionError::class, sprintf('[migration.discovery] %s: migration [%s] is missing.', $packageRoot, $migration));
        }

        throw_if($assertion instanceof Closure && $assertion() !== true, AssertionError::class, sprintf('[lifecycle.install-upgrade] %s: lifecycle assertion failed.', $packageRoot));
    }
}
