<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

it('quarantines a provider in memory when the record write fails after the schema probe passes', function (): void {
    CapellCore::registerPackage('vendor/read-only-extension', version: '1.0.0');
    CapellCore::markPackageInstalled('vendor/read-only-extension');

    $event = 'eloquent.saving: ' . CapellExtension::class;
    $fired = 0;
    Event::listen($event, function () use (&$fired): never {
        $fired++;

        throw new QueryException('testing', 'insert into capell_extensions', [], new PDOException('attempt to write a readonly database'));
    });

    try {
        CapellCore::markPackageProviderQuarantined(
            name: 'vendor/read-only-extension',
            provider: 'Vendor\\ReadOnly\\ReadOnlyServiceProvider',
            reason: 'Provider registration failed.',
        );
    } finally {
        Event::forget($event);
    }

    expect(CapellCore::isPackageInstalled('vendor/read-only-extension'))->toBeFalse()
        ->and($fired)->toBe(1);
});

it('quarantines a provider in memory when the cache store throws', function (): void {
    CapellCore::registerPackage('vendor/cache-down-extension', version: '1.0.0');
    CapellCore::markPackageInstalled('vendor/cache-down-extension');

    $store = Mockery::mock(CacheRepository::class);
    $store->shouldReceive('forget')->andThrow(new RuntimeException('cache table unavailable'));
    Cache::shouldReceive('supportsTags')->andReturnFalse();
    Cache::shouldReceive('store')->andReturn($store);

    CapellCore::markPackageProviderQuarantined(
        name: 'vendor/cache-down-extension',
        provider: 'Vendor\\CacheDown\\CacheDownServiceProvider',
        reason: 'Provider registration failed.',
    );

    expect(CapellCore::isPackageInstalled('vendor/cache-down-extension'))->toBeFalse();
});

it('quarantines a provider in memory when a saving listener throws an unexpected exception', function (): void {
    CapellCore::registerPackage('vendor/listener-extension', version: '1.0.0');
    CapellCore::markPackageInstalled('vendor/listener-extension');

    $event = 'eloquent.saving: ' . CapellExtension::class;
    $fired = 0;
    Event::listen($event, function () use (&$fired): never {
        $fired++;

        throw new RuntimeException('observer failed');
    });

    try {
        CapellCore::markPackageProviderQuarantined(
            name: 'vendor/listener-extension',
            provider: 'Vendor\\Listener\\ListenerServiceProvider',
            reason: 'Provider registration failed.',
        );
    } finally {
        Event::forget($event);
    }

    expect(CapellCore::isPackageInstalled('vendor/listener-extension'))->toBeFalse()
        ->and($fired)->toBe(1);
});
