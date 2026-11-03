<?php

declare(strict_types=1);

use Capell\Core\Actions\Extensions\HashExtensionContentsAction;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Testing\PendingCommand;

it('prints the reproducible hash of a package directory', function (): void {
    $path = sys_get_temp_dir() . '/capell-hash-command-' . bin2hex(random_bytes(8));
    mkdir($path);
    file_put_contents($path . '/file.txt', 'fixture');
    try {
        $command = $this->artisan('capell:extensions:hash', ['path' => $path]);
        assert($command instanceof PendingCommand);
        $command->expectsOutput(HashExtensionContentsAction::run($path))
            ->assertSuccessful()
            ->run();
    } finally {
        new Filesystem()->deleteDirectory($path);
    }
});
