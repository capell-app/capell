<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Policies;

use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Admin\Tests\Unit\Policies\Fixtures\TestPermissionResolver;
use ReflectionClass;

/**
 * The ResolvesShieldPermission trait builds Spatie permission names using
 * Filament Shield's configured case + separator. Hardcoding the format
 * (e.g. "update_page") silently breaks whenever a host app configures a
 * different naming convention, because `hasPermissionTo()` throws
 * `PermissionDoesNotExist` on a miss and the Gate layer swallows it as
 * "denied" — hiding the real cause.
 *
 * These tests lock in the trait's format-agnostic behaviour across the
 * default (pascal + ':') and legacy-style (snake + '_') configurations.
 */
beforeEach(function (): void {
    // Reset Shield config between tests; the container caches the config DTO.
    Utils::getConfig(); // Warm up
});

it('builds permission names using Shield default config (pascal + colon)', function (): void {
    config()->set('filament-shield.permissions.case', 'pascal');
    config()->set('filament-shield.permissions.separator', ':');

    // Clear memoized config
    $reflection = new ReflectionClass(Utils::class);
    if ($reflection->hasProperty('config')) {
        $prop = $reflection->getProperty('config');
        $prop->setValue(null, null);
    }

    expect(TestPermissionResolver::permission('update', 'Page'))->toBe('Update:Page')
        ->and(TestPermissionResolver::permission('view_any', 'Page'))->toBe('ViewAny:Page')
        ->and(TestPermissionResolver::permission('force_delete_any', 'PageUrl'))->toBe('ForceDeleteAny:PageUrl');
});

it('builds permission names using legacy Shield config (snake + underscore)', function (): void {
    config()->set('filament-shield.permissions.case', 'lower_snake');
    config()->set('filament-shield.permissions.separator', '_');

    $reflection = new ReflectionClass(Utils::class);
    if ($reflection->hasProperty('config')) {
        $prop = $reflection->getProperty('config');
        $prop->setValue(null, null);
    }

    expect(TestPermissionResolver::permission('update', 'Page'))->toBe('update_page')
        ->and(TestPermissionResolver::permission('view_any', 'Page'))->toBe('view_any_page')
        ->and(TestPermissionResolver::permission('force_delete_any', 'PageUrl'))->toBe('force_delete_any_page_url');
});
