<?php

declare(strict_types=1);

use Capell\Core\Providers\CapellServiceProvider;
use Capell\Core\Support\Config\LegacyConfigFallback;
use Illuminate\Config\Repository;
use Illuminate\Support\ServiceProvider;

it('keeps honouring settings an install still makes under the unprefixed keys', function (): void {
    // An existing app boots with its own config/backup.php and config/redirects.php and no prefixed files.
    config()->set('capell-backup');
    config()->set('capell-redirects');
    config()->set('backup', ['retain' => 5, 'signing' => ['key_id' => 'legacy-key'], 'backup' => ['name' => 'spatie']]);
    config()->set('redirects', ['auto_redirects' => ['enabled' => false]]);

    $deprecations = [];
    set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
        $deprecations[] = $message;

        return true;
    }, E_USER_DEPRECATED);

    try {
        $carried = LegacyConfigFallback::carryRenamedKeys(resolve(Illuminate\Contracts\Config\Repository::class));
    } finally {
        restore_error_handler();
    }

    expect($carried)->toBe(['backup', 'redirects'])->and($deprecations)->toHaveCount(2);

    expect(config('capell-backup.retain'))->toBe(5)
        ->and(config('capell-backup.signing.key_id'))->toBe('legacy-key')
        ->and(config('capell-backup'))->not->toHaveKey('backup')
        ->and(config('capell-redirects.auto_redirects.enabled'))->toBeFalse();
});

it('prefers the prefixed key when both are set', function (): void {
    $config = new Repository([
        'backup' => ['retain' => 5, 'prefix' => 'old'],
        'capell-backup' => ['retain' => 9],
    ]);

    $carried = LegacyConfigFallback::apply($config, 'backup', 'capell-backup', dirname(__DIR__, 3) . '/config/capell-backup.php');

    expect($carried)->toBe(['retain', 'prefix'])
        ->and($config->get('capell-backup.retain'))->toBe(9)
        ->and($config->get('capell-backup.prefix'))->toBe('old');
});

it('carries nothing when the legacy key is absent or holds no Capell setting', function (): void {
    $defaults = dirname(__DIR__, 3) . '/config/capell-redirects.php';

    expect(LegacyConfigFallback::apply(new Repository, 'redirects', 'capell-redirects', $defaults))->toBe([])
        ->and(LegacyConfigFallback::apply(new Repository(['redirects' => ['other' => 1]]), 'redirects', 'capell-redirects', $defaults))->toBe([]);
});

it('raises a deprecation naming the file that replaces the old key', function (): void {
    $messages = [];
    set_error_handler(static function (int $level, string $message) use (&$messages): bool {
        $messages[] = $message;

        return true;
    }, E_USER_DEPRECATED);

    try {
        LegacyConfigFallback::deprecate('backup', 'capell-backup');
    } finally {
        restore_error_handler();
    }

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('[backup]')->toContain('config/capell-backup.php');
});

it('publishes Core config only under prefixed names', function (): void {
    $published = collect(ServiceProvider::pathsToPublish(null, 'capell-config'))->values()
        ->map(static fn (string $path): string => basename($path));

    expect($published)->not->toContain('backup.php')->not->toContain('redirects.php')
        ->and($published)->toContain('capell-backup.php')->toContain('capell-redirects.php');
});

it('carries legacy settings during provider registration, before package config is merged', function (): void {
    config()->set('capell-backup');
    config()->set('backup', ['retain' => 5]);

    $deprecations = [];
    set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
        $deprecations[] = $message;

        return true;
    }, E_USER_DEPRECATED);

    try {
        new CapellServiceProvider(app())->registeringPackage();
    } finally {
        restore_error_handler();
    }

    // Only the carried value exists at this point; the package defaults merge in afterwards without overriding it.
    expect(config('capell-backup'))->toBe(['retain' => 5])->and($deprecations)->toHaveCount(1);
});
