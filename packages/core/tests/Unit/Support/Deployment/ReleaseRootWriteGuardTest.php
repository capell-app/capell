<?php

declare(strict_types=1);

use Capell\Core\Enums\Deployment\ReleaseRootWriteRefusal;
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
    throw_unless(is_string($temporaryRoot), RuntimeException::class, 'The system temporary directory must resolve to a canonical path.');

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

it('reports the refusal without throwing and returns null when the write is allowed', function (): void {
    $temporaryRoot = realpath(sys_get_temp_dir());
    throw_unless(is_string($temporaryRoot), RuntimeException::class, 'The system temporary directory must resolve to a canonical path.');

    $root = $temporaryRoot . '/capell-checkable-release-' . bin2hex(random_bytes(4));
    mkdir($root . '/database', 0755, true);

    config()->set('capell.release_root_mode', 'mutable');
    config()->set('capell.server_side_tooling', true);

    try {
        $guard = new ReleaseRootWriteGuard;

        expect($guard->check('Publishing pending Capell migrations', ['database/migrations'], $root))->toBeNull()
            ->and($guard->refusalReason('Publishing pending Capell migrations', ['database/migrations'], $root))->toBeNull();
    } finally {
        rmdir($root . '/database');
        rmdir($root);
    }
});

it('returns the same message the assertion throws, for the same host', function (): void {
    config()->set('capell.release_root_mode', 'immutable');
    config()->set('capell.server_side_tooling', true);

    $guard = new ReleaseRootWriteGuard;
    $reported = $guard->check('Installing a Marketplace extension with Composer', ['composer.json'], base_path());

    $thrown = null;

    try {
        $guard->assertWritable('Installing a Marketplace extension with Composer', ['composer.json'], base_path());
    } catch (RuntimeException $exception) {
        $thrown = $exception->getMessage();
    }

    expect($reported)->toBe($thrown)
        ->and($reported)->toContain('CAPELL_RELEASE_ROOT_MODE is immutable');
});

it('classifies a deliberately immutable release root as refused by design', function (): void {
    config()->set('capell.release_root_mode', 'atomic');
    config()->set('capell.server_side_tooling', true);

    $reason = new ReleaseRootWriteGuard()->refusalReason(
        'Installing a Marketplace extension with Composer',
        ['composer.json'],
        base_path(),
    );

    expect($reason)->toBe(ReleaseRootWriteRefusal::ReleaseRootNotMutable)
        ->and($reason?->isByDesign())->toBeTrue();
});

it('classifies a release root that is not an absolute path as misconfigured', function (): void {
    config()->set('capell.release_root_mode', 'mutable');
    config()->set('capell.server_side_tooling', true);

    $reason = new ReleaseRootWriteGuard()->refusalReason(
        'Installing a Marketplace extension with Composer',
        ['composer.json'],
        'relative/release/root',
    );

    expect($reason)->toBe(ReleaseRootWriteRefusal::ReleaseRootNotAbsolute)
        ->and($reason?->isByDesign())->toBeFalse();
});

it('stops treating a windows release root as a relative path', function (string $windowsRoot): void {
    config()->set('capell.release_root_mode', 'mutable');
    config()->set('capell.server_side_tooling', true);

    $reason = new ReleaseRootWriteGuard()->refusalReason(
        'Installing a Marketplace extension with Composer',
        ['composer.json'],
        $windowsRoot,
    );

    expect($reason)->not->toBe(ReleaseRootWriteRefusal::ReleaseRootNotAbsolute);
})->with([
    'drive letter with backslashes' => 'C:\\inetpub\\capell',
    'drive letter with forward slashes' => 'C:/inetpub/capell',
    'unc share' => '\\\\fileserver\\releases\\capell',
]);

it('accepts a windows style release root that exists and is writable', function (): void {
    $temporaryRoot = realpath(sys_get_temp_dir());
    throw_unless(is_string($temporaryRoot), RuntimeException::class, 'The system temporary directory must resolve to a canonical path.');

    $root = $temporaryRoot . DIRECTORY_SEPARATOR . 'capell-windows-release-' . bin2hex(random_bytes(4));
    mkdir($root . DIRECTORY_SEPARATOR . 'database', 0755, true);

    config()->set('capell.release_root_mode', 'mutable');
    config()->set('capell.server_side_tooling', true);

    try {
        // A drive-letter root cannot exist on the test host, so the closest
        // observable proof that the drive-letter rule no longer blocks a real
        // directory is that an existing root still passes unchanged.
        expect(new ReleaseRootWriteGuard()->refusalReason(
            'Publishing pending Capell migrations',
            ['database/migrations'],
            $root,
        ))->toBeNull();
    } finally {
        rmdir($root . DIRECTORY_SEPARATOR . 'database');
        rmdir($root);
    }
});

it('keeps refusing a windows release root that the deployment declared immutable', function (string $mode): void {
    config()->set('capell.release_root_mode', $mode);
    config()->set('capell.server_side_tooling', true);

    $reason = new ReleaseRootWriteGuard()->refusalReason(
        'Installing a Marketplace extension with Composer',
        ['composer.json'],
        'C:\\inetpub\\capell',
    );

    expect($reason)->toBe(ReleaseRootWriteRefusal::ReleaseRootNotMutable);
})->with(['immutable', 'atomic']);

it('keeps refusing a windows release root when server side tooling is disabled', function (): void {
    config()->set('capell.release_root_mode', 'mutable');
    config()->set('capell.server_side_tooling', false);

    $reason = new ReleaseRootWriteGuard()->refusalReason(
        'Installing a Marketplace extension with Composer',
        ['composer.json'],
        'C:\\inetpub\\capell',
        requiresServerSideTooling: true,
    );

    expect($reason)->toBe(ReleaseRootWriteRefusal::ServerSideToolingDisabled);
});

it('refuses a windows release root whose path cannot be written by this process', function (): void {
    config()->set('capell.release_root_mode', 'mutable');
    config()->set('capell.server_side_tooling', true);

    $reason = new ReleaseRootWriteGuard()->refusalReason(
        'Installing a Marketplace extension with Composer',
        ['composer.json'],
        'C:\\inetpub\\capell',
    );

    expect($reason)->toBe(ReleaseRootWriteRefusal::ReleaseRootPathNotWritable);
});

it('inspects each component of a windows style root rather than one giant component', function (): void {
    $parent = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'capell-component-walk-' . bin2hex(random_bytes(4));
    $release = $parent . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . '20260805120000';
    $current = $parent . DIRECTORY_SEPARATOR . 'current';
    mkdir($release, 0755, true);
    symlink($release, $current);

    config()->set('capell.release_root_mode', 'mutable');
    config()->set('capell.server_side_tooling', true);

    try {
        // The symlink is not the last component, so a walk that failed to split
        // the root into components could not possibly find it.
        $reason = new ReleaseRootWriteGuard()->refusalReason(
            'Installing a Marketplace extension with Composer',
            ['composer.json'],
            $current . DIRECTORY_SEPARATOR . 'public',
        );

        expect($reason)->toBe(ReleaseRootWriteRefusal::ReleaseRootTraversesSymlink);
    } finally {
        unlink($current);
        rmdir($release);
        rmdir($parent . DIRECTORY_SEPARATOR . 'releases');
        rmdir($parent);
    }
});

it('rejects relative write paths that traverse upwards with windows separators', function (string $relativePath): void {
    config()->set('capell.release_root_mode', 'mutable');
    config()->set('capell.server_side_tooling', true);

    expect(function () use ($relativePath): void {
        new ReleaseRootWriteGuard()->assertWritable(
            operation: 'Unsafe write',
            relativePaths: [$relativePath],
            releaseRoot: base_path(),
        );
    })->toThrow(InvalidArgumentException::class, 'without parent traversal');
})->with([
    'backslash traversal' => 'bootstrap\\..\\..\\outside',
    'windows absolute drive path' => 'C:\\Windows\\System32',
    'unc absolute path' => '\\\\fileserver\\share',
]);
