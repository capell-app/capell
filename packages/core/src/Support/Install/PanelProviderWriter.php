<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use RuntimeException;

final class PanelProviderWriter
{
    public function save(string $path, string $original, string $contents): void
    {
        $path = realpath($path) ?: $path;

        // Concurrent installer processes verify, write and record identity one at a time.
        InstallRunState::commitPanelProviderSave($path, $original, $contents, static function () use ($path, $original, $contents): void {
            throw_unless(
                in_array($path, resolve(PanelProviderTargetResolver::class)->resolve($path), true) && file_get_contents($path) === $original,
                RuntimeException::class,
                'Panel provider changed before it could be saved: ' . $path,
            );

            if ($contents !== $original) {
                $written = @file_put_contents($path, $contents);
                throw_unless($written === strlen($contents), RuntimeException::class, 'Failed to write panel provider: ' . $path);
            }
        });
    }
}
