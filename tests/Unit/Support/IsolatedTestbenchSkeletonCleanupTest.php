<?php

declare(strict_types=1);

use Capell\Tests\Support\IsolatedTestbenchSkeleton;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Date;

it('prunes only old marked skeletons belonging to this checkout', function (): void {
    $directory = sys_get_temp_dir() . '/capell-skeleton-gc-' . bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    foreach (['capell-testbench-unmarked', 'capell-testbench-dead', 'capell-testbench-live', 'capell-testbench-invalid', 'capell-testbench-current', 'capell-testbench-recent', 'unrelated'] as $name) {
        $filesystem->ensureDirectoryExists($directory . '/' . $name);
        touch($directory . '/' . $name, Date::now()->subDays(1)->getTimestamp());
    }

    $marker = fn (int $pid): string => json_encode(['checkout' => realpath(dirname(__DIR__, 3)), 'pid' => $pid], JSON_THROW_ON_ERROR);
    file_put_contents($directory . '/capell-testbench-dead/.capell-skeleton', $marker(2147483646));
    file_put_contents($directory . '/capell-testbench-live/.capell-skeleton', $marker(getmypid()));
    file_put_contents($directory . '/unrelated/.capell-skeleton', $marker(2147483646));
    touch($directory . '/capell-testbench-dead', Date::now()->subDays(1)->getTimestamp());
    touch($directory . '/capell-testbench-live', Date::now()->subDays(1)->getTimestamp());
    file_put_contents($directory . '/capell-testbench-invalid/.capell-skeleton', 'invalid');
    file_put_contents($directory . '/capell-testbench-current/.capell-skeleton', $marker(2147483646));
    file_put_contents($directory . '/capell-testbench-recent/.capell-skeleton', $marker(2147483646));
    touch($directory . '/capell-testbench-current', Date::now()->subDays(1)->getTimestamp());

    try {
        expect(IsolatedTestbenchSkeleton::removeStaleSkeletons($directory, $directory . '/capell-testbench-current', 21600, fn (int $pid): bool => $pid !== 2147483646))->toBe(1)
            ->and(is_dir($directory . '/capell-testbench-dead'))->toBeFalse()
            ->and(is_dir($directory . '/capell-testbench-unmarked'))->toBeTrue()
            ->and(is_dir($directory . '/capell-testbench-live'))->toBeTrue()
            ->and(is_dir($directory . '/unrelated'))->toBeTrue()
            ->and(is_dir($directory . '/capell-testbench-invalid'))->toBeTrue()
            ->and(is_dir($directory . '/capell-testbench-current'))->toBeTrue()
            ->and(is_dir($directory . '/capell-testbench-recent'))->toBeTrue();
    } finally {
        $filesystem->deleteDirectory($directory);
    }
});

it('ignores a missing skeleton directory', function (): void {
    expect(IsolatedTestbenchSkeleton::removeStaleSkeletons(sys_get_temp_dir() . '/capell-skeleton-gc-missing-' . bin2hex(random_bytes(8))))->toBe(0);
});
