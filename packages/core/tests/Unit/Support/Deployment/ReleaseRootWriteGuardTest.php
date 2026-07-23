<?php

declare(strict_types=1);

use Capell\Core\Support\Deployment\ReleaseRootWriteGuard;

it('blocks runtime tooling when the deployment does not opt in', function (): void {
    config()->set('capell.release_root_mode', 'mutable');
    config()->set('capell.server_side_tooling', false);

    expect(function (): void {
        (new ReleaseRootWriteGuard)->assertWritable(
            operation: 'Installing a Marketplace extension with Composer',
            relativePaths: ['composer.json'],
            releaseRoot: base_path(),
            requiresServerSideTooling: true,
        );
    })->toThrow(
        RuntimeException::class,
        'Installing a Marketplace extension with Composer is blocked because CAPELL_SERVER_SIDE_TOOLING is disabled',
    );
});

it('accepts a directly addressed mutable release root', function (): void {
    $temporaryRoot = realpath(sys_get_temp_dir());
    if (! is_string($temporaryRoot)) {
        throw new RuntimeException('The system temporary directory must resolve to a canonical path.');
    }

    $root = $temporaryRoot . '/capell-mutable-release-' . bin2hex(random_bytes(4));
    mkdir($root . '/database', 0755, true);

    config()->set('capell.release_root_mode', 'mutable');
    config()->set('capell.server_side_tooling', true);

    try {
        (new ReleaseRootWriteGuard)->assertWritable(
            operation: 'Publishing pending Capell migrations',
            relativePaths: ['database/migrations'],
            releaseRoot: $root,
        );

        expect(is_dir($root . '/database'))->toBeTrue();
    } finally {
        rmdir($root . '/database');
        rmdir($root);
    }
});

it('blocks explicitly immutable and atomic release layouts', function (string $mode): void {
    config()->set('capell.release_root_mode', $mode);
    config()->set('capell.server_side_tooling', true);

    expect(function (): void {
        (new ReleaseRootWriteGuard)->assertWritable(
            operation: 'Installing a Marketplace extension with Composer',
            relativePaths: ['composer.json'],
            releaseRoot: base_path(),
        );
    })->toThrow(
        RuntimeException::class,
        'Installing a Marketplace extension with Composer is blocked because CAPELL_RELEASE_ROOT_MODE is ' . $mode,
    );
})->with(['immutable', 'atomic']);

it('blocks a mutable mode root that traverses an atomic release symlink', function (): void {
    $parent = sys_get_temp_dir() . '/capell-atomic-release-' . bin2hex(random_bytes(4));
    $release = $parent . '/releases/20260723120000';
    $current = $parent . '/current';
    mkdir($release, 0755, true);
    symlink($release, $current);

    config()->set('capell.release_root_mode', 'mutable');
    config()->set('capell.server_side_tooling', true);

    try {
        expect(function () use ($current): void {
            (new ReleaseRootWriteGuard)->assertWritable(
                operation: 'Installing a Marketplace extension with Composer',
                relativePaths: ['composer.json'],
                releaseRoot: $current,
            );
        })->toThrow(
            RuntimeException::class,
            'Writing through an atomic current-release symlink can modify an old release',
        );
    } finally {
        unlink($current);
        rmdir($release);
        rmdir($parent . '/releases');
        rmdir($parent);
    }
});

it('rejects release-root paths with parent traversal', function (): void {
    config()->set('capell.release_root_mode', 'mutable');
    config()->set('capell.server_side_tooling', true);

    expect(function (): void {
        (new ReleaseRootWriteGuard)->assertWritable(
            operation: 'Unsafe write',
            relativePaths: ['../outside'],
            releaseRoot: base_path(),
        );
    })->toThrow(InvalidArgumentException::class, 'without parent traversal');
});
