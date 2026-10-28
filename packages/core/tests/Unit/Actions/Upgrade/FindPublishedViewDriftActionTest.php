<?php

declare(strict_types=1);

use Capell\Core\Actions\Upgrade\FindPublishedViewDriftAction;
use Illuminate\Filesystem\Filesystem;

it('finds changed and removed upstream views while ignoring matching views', function (): void {
    $root = sys_get_temp_dir() . '/capell-view-drift-' . bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $filesystem->makeDirectory($root . '/published/components', 0755, true);
    $filesystem->makeDirectory($root . '/upstream/components', 0755, true);
    $filesystem->put($root . '/published/components/same.blade.php', 'same');
    $filesystem->put($root . '/upstream/components/same.blade.php', 'same');
    $filesystem->put($root . '/published/components/changed.blade.php', 'custom');
    $filesystem->put($root . '/upstream/components/changed.blade.php', 'updated');
    $filesystem->put($root . '/published/components/removed.blade.php', 'old');

    try {
        expect(FindPublishedViewDriftAction::run($root . '/published', $root . '/upstream'))->toBe([
            'different' => ['components/changed.blade.php'],
            'missing' => ['components/removed.blade.php'],
        ]);
    } finally {
        $filesystem->deleteDirectory($root);
    }
});

it('returns no findings when no views have been published', function (): void {
    expect(FindPublishedViewDriftAction::run('/nonexistent/capell-views', '/nonexistent/upstream'))->toBe([
        'different' => [],
        'missing' => [],
    ]);
});
