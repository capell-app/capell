<?php

declare(strict_types=1);
use Illuminate\Filesystem\Filesystem;

function acquireCapellInstallFilesystemLock(): void
{
    $lockDirectory = sys_get_temp_dir() . '/capell-test-locks';

    if (! is_dir($lockDirectory)) {
        mkdir($lockDirectory, 0755, true);
    }

    $lockHandle = fopen($lockDirectory . '/install-filesystem.lock', 'c');

    throw_if($lockHandle === false, RuntimeException::class, 'Unable to open Capell install filesystem test lock.');

    flock($lockHandle, LOCK_EX);

    $GLOBALS['capellInstallFilesystemLockHandle'] = $lockHandle;
}

function releaseCapellInstallFilesystemLock(): void
{
    $lockHandle = $GLOBALS['capellInstallFilesystemLockHandle'] ?? null;

    if (! is_resource($lockHandle)) {
        return;
    }

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);

    unset($GLOBALS['capellInstallFilesystemLockHandle']);
}

function preserveTestbenchPackageManifestFilesDuringPackageRemoval(): void
{
    app()->instance(Filesystem::class, new class extends Filesystem
    {
        public function delete($paths): bool
        {
            $preservedPaths = [
                base_path('bootstrap/cache/packages.php'),
                base_path('bootstrap/cache/services.php'),
            ];

            $filteredPaths = collect((array) $paths)
                ->reject(fn (string $path): bool => in_array($path, $preservedPaths, true))
                ->values()
                ->all();

            if ($filteredPaths === []) {
                return true;
            }

            return parent::delete($filteredPaths);
        }
    });
}
