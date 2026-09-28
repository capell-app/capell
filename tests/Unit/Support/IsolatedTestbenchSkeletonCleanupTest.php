<?php

declare(strict_types=1);

use Capell\Tests\Support\IsolatedTestbenchSkeleton;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Date;

it('removes only stale skeletons and keeps the current and recent ones', function (): void {
    $directory = sys_get_temp_dir() . '/capell-skeleton-gc-' . bin2hex(random_bytes(8));
    $filesystem = new Filesystem;

    foreach (['stale', 'recent', 'current'] as $name) {
        $filesystem->ensureDirectoryExists($directory . '/' . $name . '/storage/app');
        file_put_contents($directory . '/' . $name . '/storage/app/marker', $name);
    }

    $outside = $directory . '-outside';
    $filesystem->ensureDirectoryExists($outside);
    file_put_contents($outside . '/keep', 'keep');
    symlink($outside, $directory . '/linked');

    touch($directory . '/stale', Date::now()->subDay()->getTimestamp());
    touch($directory . '/current', Date::now()->subDay()->getTimestamp());

    try {
        $removed = IsolatedTestbenchSkeleton::removeStaleSkeletons($directory, $directory . '/current');

        expect($removed)->toBe(1)
            ->and(is_dir($directory . '/stale'))->toBeFalse()
            ->and(is_dir($directory . '/recent'))->toBeTrue()
            ->and(is_dir($directory . '/current'))->toBeTrue()
            ->and(file_exists($outside . '/keep'))->toBeTrue();
    } finally {
        @unlink($directory . '/linked');
        $filesystem->deleteDirectory($directory);
        $filesystem->deleteDirectory($outside);
    }
});

it('ignores a missing skeleton directory', function (): void {
    expect(IsolatedTestbenchSkeleton::removeStaleSkeletons(sys_get_temp_dir() . '/capell-skeleton-gc-missing-' . bin2hex(random_bytes(8))))->toBe(0);
});

it('keeps a live owner skeleton however old and removes a dead owner skeleton however recent', function (): void {
    $directory = sys_get_temp_dir() . '/capell-skeleton-owner-' . bin2hex(random_bytes(8));
    $filesystem = new Filesystem;

    $filesystem->ensureDirectoryExists($directory . '/live');
    file_put_contents($directory . '/live/.capell-skeleton-owner', (string) getmypid());
    touch($directory . '/live', Date::now()->subDays(2)->getTimestamp());

    $filesystem->ensureDirectoryExists($directory . '/dead');
    file_put_contents($directory . '/dead/.capell-skeleton-owner', '2147483646');

    try {
        $removed = IsolatedTestbenchSkeleton::removeStaleSkeletons($directory);

        expect($removed)->toBe(1)
            ->and(is_dir($directory . '/live'))->toBeTrue()
            ->and(is_dir($directory . '/dead'))->toBeFalse();
    } finally {
        $filesystem->deleteDirectory($directory);
    }
});
