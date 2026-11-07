<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Testing\PendingCommand;

it('prints the reproducible hash of a package directory', function (): void {
    $path = sys_get_temp_dir() . '/capell-hash-command-' . bin2hex(random_bytes(8));
    mkdir($path);
    file_put_contents($path . '/123', 'first');
    file_put_contents($path . '/file.txt', 'second');
    $expected = hash('sha256', '3:123:' . hash('sha256', 'first') . "\n" . '8:file.txt:' . hash('sha256', 'second') . "\n");
    try {
        $command = $this->artisan('capell:extensions:hash', ['path' => $path]);
        assert($command instanceof PendingCommand);
        $command->expectsOutput($expected)
            ->assertSuccessful()
            ->run();
    } finally {
        new Filesystem()->deleteDirectory($path);
    }
});
