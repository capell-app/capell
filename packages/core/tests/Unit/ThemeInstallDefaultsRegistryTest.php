<?php

declare(strict_types=1);

use Capell\Core\Support\Themes\ThemeInstallDefaultsRegistry;

it('runs the defaults handler registered for a theme', function (): void {
    $registry = new ThemeInstallDefaultsRegistry;
    $calls = [];
    $registry->register('default', function () use (&$calls): void {
        $calls[] = 'installed';
    });

    expect($registry->has('default'))->toBeTrue()
        ->and($registry->install('default'))->toBeTrue()
        ->and($calls)->toBe(['installed']);
});

it('is a safe no-op when a theme has no defaults handler', function (): void {
    expect((new ThemeInstallDefaultsRegistry)->install('custom'))->toBeFalse();
});

it('rejects duplicate theme handlers', function (): void {
    $registry = new ThemeInstallDefaultsRegistry;
    $registry->register('default', static function (): void {});
    $registry->register('default', static function (): void {});
})->throws(LogicException::class);
