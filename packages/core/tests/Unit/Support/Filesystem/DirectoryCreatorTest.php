<?php

declare(strict_types=1);

use Capell\Core\Support\Filesystem\DirectoryCreator;

it('creates a missing directory recursively with the requested mode', function (): void {
    $root = sys_get_temp_dir() . '/capell-directory-creator-' . bin2hex(random_bytes(8));
    $path = $root . '/nested/directory';

    try {
        DirectoryCreator::ensure($path, 0700, 'Unable to create directory.');

        expect($path)->toBeDirectory()
            ->and(fileperms($path) & 0777)->toBe(0700);
    } finally {
        if (is_dir($path)) {
            rmdir($path);
        }

        if (is_dir(dirname($path))) {
            rmdir(dirname($path));
        }

        if (is_dir($root)) {
            rmdir($root);
        }
    }
});

it('accepts an existing directory', function (): void {
    DirectoryCreator::ensure(sys_get_temp_dir(), 0700, 'Unable to create directory.');

    expect(sys_get_temp_dir())->toBeDirectory();
});

it('throws the supplied message when the directory cannot be created', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'capell-directory-creator-');

    expect($file)->not->toBeFalse();

    try {
        @DirectoryCreator::ensure($file . '/directory', 0700, 'Expected failure message.');
    } finally {
        unlink((string) $file);
    }
})->throws(RuntimeException::class, 'Expected failure message.');
