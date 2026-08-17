<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

it('loads selected manifest runtime providers before frozen surfaces during a cold cloud install', function (): void {
    $root = dirname(__DIR__, 5);
    $scratchPath = sys_get_temp_dir() . '/capell-cold-cloud-install-' . bin2hex(random_bytes(8));
    $databasePath = $scratchPath . '/database/cold-install.sqlite';

    $process = new Process(
        [
            PHP_BINARY,
            $root . '/packages/core/tests/fixtures/cold-cloud-install-provider.php',
            $root,
            $scratchPath,
            $databasePath,
        ],
        env: [
            'CAPELL_INSTALL_MODE' => 'cloud',
            'CAPELL_INSTALL_PACKAGES' => 'vendor/cold-cloud-install',
        ],
    );
    $process->setTimeout(60);

    try {
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and(is_file($databasePath))->toBeTrue()
            ->and(json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR))->toBe([
                'event_registered_before_install' => true,
                'event_registry_frozen' => true,
                'package_installed' => true,
            ]);
    } finally {
        File::deleteDirectory($scratchPath);
    }
});
