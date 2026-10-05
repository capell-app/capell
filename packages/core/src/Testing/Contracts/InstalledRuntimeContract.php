<?php

declare(strict_types=1);

namespace Capell\Core\Testing\Contracts;

use AssertionError;
use Closure;
use Illuminate\Foundation\Application;

/**
 * A package supplies an isolated boot factory and its observable surface snapshot.
 * Arrays are compared verbatim: callers must retain multiplicity and order.
 */
final class InstalledRuntimeContract
{
    /**
     * @param  class-string  $provider
     * @param  Closure(bool): Application  $boot
     * @param  Closure(Application): array<string, mixed>  $snapshot
     * @param  Closure(Application): void  $install
     * @param  Closure(Application): void  $refresh
     * @param  Closure(Application): void  $assertAbsent
     * @param  Closure(Application): void  $assertDeclared
     */
    public static function assertParity(
        string $provider,
        Closure $boot,
        Closure $snapshot,
        Closure $install,
        Closure $refresh,
        Closure $assertAbsent,
        Closure $assertDeclared,
    ): void {
        $fresh = $boot(true);
        $assertDeclared($fresh);
        $expected = $snapshot($fresh);

        $late = $boot(false);
        $originalProvider = $late->getProvider($provider);
        throw_if($originalProvider === null, AssertionError::class, 'The uninstalled main provider must be explicitly preloaded.');

        $assertAbsent($late);
        $install($late);
        $assertDeclared($late);
        self::same($expected, $snapshot($late), 'installation');
        $refresh($late);
        $assertDeclared($late);
        self::same($expected, $snapshot($late), 'repeated refresh');

        throw_if($late->getProvider($provider) !== $originalProvider, AssertionError::class, 'Installation replaced the preloaded provider instance.');
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private static function same(array $expected, array $actual, string $phase): void
    {
        if ($expected !== $actual) {
            throw new AssertionError('Installed runtime differs after ' . $phase . ': ' . json_encode([
                'fresh' => $expected,
                'actual' => $actual,
            ], JSON_THROW_ON_ERROR));
        }
    }
}
