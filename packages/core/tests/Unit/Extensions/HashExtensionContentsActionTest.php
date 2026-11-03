<?php

declare(strict_types=1);

use Capell\Core\Actions\Extensions\HashExtensionContentsAction;
use Illuminate\Filesystem\Filesystem;

it('hashes paths and bytes independently of creation order and modification time', function (): void {
    $root = sys_get_temp_dir() . '/capell-content-hash-' . bin2hex(random_bytes(8));
    mkdir($root . '/a/nested', 0777, true);
    mkdir($root . '/b/nested', 0777, true);
    try {
        file_put_contents($root . '/a/first', 'one');
        file_put_contents($root . '/a/nested/second', 'two');
        file_put_contents($root . '/b/nested/second', 'two');
        file_put_contents($root . '/b/first', 'one');
        touch($root . '/b/first', 1000000000);
        $hash = HashExtensionContentsAction::run($root . '/a');
        expect(HashExtensionContentsAction::run($root . '/b'))->toBe($hash);

        mkdir($root . '/b/.git');
        file_put_contents($root . '/b/.git/config', 'ignored');
        expect(HashExtensionContentsAction::run($root . '/b'))->toBe($hash);

        file_put_contents($root . '/b/first', 'One');
        expect(HashExtensionContentsAction::run($root . '/b'))->not->toBe($hash);
        file_put_contents($root . '/b/first', 'one');
        file_put_contents($root . '/b/extra', '');
        expect(HashExtensionContentsAction::run($root . '/b'))->not->toBe($hash);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('excludes nested VCS metadata and refuses symbolic links', function (): void {
    $root = sys_get_temp_dir() . '/capell-content-hash-' . bin2hex(random_bytes(8));
    mkdir($root . '/src', 0777, true);
    try {
        file_put_contents($root . '/src/Provider.php', '<?php');
        $hash = HashExtensionContentsAction::run($root);
        mkdir($root . '/src/.svn');
        file_put_contents($root . '/src/.svn/entries', 'ignored');
        expect(HashExtensionContentsAction::run($root))->toBe($hash);

        symlink($root . '/src/Provider.php', $root . '/src/Linked.php');
        expect(fn () => HashExtensionContentsAction::run($root))->toThrow(RuntimeException::class);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});
