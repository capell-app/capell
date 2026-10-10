<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

it('writes Rector caches inside the checkout whose configuration is loaded', function (): void {
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir() . '/capell-rector-cache-' . bin2hex(random_bytes(6));
    mkdir($directory);
    mkdir($directory . '/scripts');
    mkdir($directory . '/tests');
    // Copy the executable configuration unchanged; its checkout root is now owned by this test.
    copy($root . '/rector.php', $directory . '/rector.php');
    file_put_contents($directory . '/Fixture.php', "<?php\n\ndeclare(strict_types=1);\n\nfunction fixture(): int\n{\n    return 1;\n}\n");
    $process = new Process([
        PHP_BINARY, $root . '/vendor/bin/rector', 'process', $directory . '/Fixture.php',
        '--config=' . $directory . '/rector.php', '--dry-run', '--no-progress-bar',
    ], $root);
    try {
        expect($process->run())->toBe(0, $process->getErrorOutput() . $process->getOutput());
        // Stored cache files prove both caches stay local without depending on their filenames.
        expect($directory . '/var/rector/files')->toBeDirectory();
        expect(File::allFiles($directory . '/var/rector/files'))->not->toBeEmpty()
            ->and(array_filter(
                File::allFiles($directory . '/var/rector'),
                static fn (SplFileInfo $file): bool => ! str_starts_with($file->getPathname(), $directory . '/var/rector/files/'),
            ))->not->toBeEmpty();
    } finally {
        File::deleteDirectory($directory);
    }
});
