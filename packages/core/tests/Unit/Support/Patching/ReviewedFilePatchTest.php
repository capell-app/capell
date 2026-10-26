<?php

declare(strict_types=1);

use Capell\Core\Support\Patching\FilePatchPlan;
use Capell\Core\Support\Patching\PhpFileEditor;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;

it('keeps separate original backups when two reviewed edits touch one PHP file within one second', function (): void {
    $directory = sys_get_temp_dir() . '/capell-patch-backups-' . uniqid();
    File::ensureDirectoryExists($directory);
    $path = $directory . '/config.php';
    $storage = app()->storagePath();
    app()->useStoragePath($directory . '/storage');
    Date::setTestNow('2026-10-25 12:00:00');
    try {
        File::put($path, '<?php return ["first" => true];');
        $first = new PhpFileEditor($path)->backup();
        File::put($path, '<?php return ["second" => true];');
        $second = new PhpFileEditor($path)->backup();
        expect($second)->not->toBe($first)
            ->and(File::get($first))->toContain('first')
            ->and(File::get($second))->toContain('second');
    } finally {
        Date::setTestNow();
        app()->useStoragePath($storage);
        File::deleteDirectory($directory);
    }
});

it('redacts literal values and comments before bounding a PHP edit while retaining its structural scope', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-preview-');
    try {
        $before = "<?php\nreturn ['channels' => []];\n";
        $after = "<?php\nreturn ['channels' => ['secret' => 'private-value', 'capell' => ['driver' => 'single']]]; // private-comment\n";
        File::put($path, $before);
        $preview = new FilePatchPlan($path, 'config/logging.php', $before, $after, visiblePhpArrayKeys: ['channels', 'capell', 'driver'])->preview();
        $safe = json_encode($preview->toArray(), JSON_THROW_ON_ERROR);
        expect($safe)->toContain('channels')->toContain('capell')->toContain('driver')
            ->not->toContain('private-value')->not->toContain('private-comment')->not->toContain('secret');
        expect(File::get($path))->toBe($before);
    } finally {
        unlink($path);
    }
});
