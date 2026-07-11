<?php

declare(strict_types=1);

use Capell\Core\Support\Settings\SettingsSchemaBootstrapper;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;

it('registers callbacks via extend', function (): void {
    $registry = new SettingsSchemaRegistry;
    $bootstrapper = new SettingsSchemaBootstrapper($registry);

    $called = false;

    $bootstrapper->extend(function (SettingsSchemaRegistry $reg) use (&$called, $registry): void {
        expect($reg)->toBe($registry);
        $called = true;
    });

    expect($called)->toBeFalse();

    $bootstrapper->bootstrap();

    expect($called)->toBeTrue();
});

it('executes multiple callbacks in order', function (): void {
    $registry = new SettingsSchemaRegistry;
    $bootstrapper = new SettingsSchemaBootstrapper($registry);

    $order = [];

    $bootstrapper->extend(function () use (&$order): void {
        $order[] = 'first';
    });

    $bootstrapper->extend(function () use (&$order): void {
        $order[] = 'second';
    });

    $bootstrapper->extend(function () use (&$order): void {
        $order[] = 'third';
    });

    expect($order)->toBeEmpty();

    $bootstrapper->bootstrap();

    expect($order)->toBe(['first', 'second', 'third']);
});

it('passes the registry to all callbacks', function (): void {
    $registry = new SettingsSchemaRegistry;
    $bootstrapper = new SettingsSchemaBootstrapper($registry);

    $callCount = 0;

    $bootstrapper->extend(function (SettingsSchemaRegistry $reg) use (&$callCount, $registry): void {
        expect($reg)->toBe($registry);
        $callCount++;
    });

    $bootstrapper->extend(function (SettingsSchemaRegistry $reg) use (&$callCount, $registry): void {
        expect($reg)->toBe($registry);
        $callCount++;
    });

    $bootstrapper->bootstrap();

    expect($callCount)->toBe(2);
});

it('handles empty callback list without error', function (): void {
    $registry = new SettingsSchemaRegistry;
    $bootstrapper = new SettingsSchemaBootstrapper($registry);

    $bootstrapper->bootstrap();

    expect(true)->toBeTrue();
});

it('allows multiple bootstrap invocations', function (): void {
    $registry = new SettingsSchemaRegistry;
    $bootstrapper = new SettingsSchemaBootstrapper($registry);

    $count = 0;

    $bootstrapper->extend(function () use (&$count): void {
        $count++;
    });

    $bootstrapper->bootstrap();

    expect($count)->toBe(1);

    $bootstrapper->bootstrap();

    expect($count)->toBe(2);
});
