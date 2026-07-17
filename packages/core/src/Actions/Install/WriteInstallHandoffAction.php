<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Data\Install\InstallHandoffData;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

final class WriteInstallHandoffAction
{
    use AsObject;

    public function handle(InstallHandoffData $handoff, string $path): string
    {
        $path = trim($path);
        $parent = dirname($path);

        if ($path === '' || ! is_dir($parent) || ! is_writable($parent)) {
            throw new RuntimeException('Install handoff parent directory must already exist and be writable.');
        }

        if (is_dir($path)) {
            throw new RuntimeException('Install handoff path must identify a JSON file, not a directory.');
        }

        $json = json_encode(
            $handoff->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
        $temporaryPath = $path . '.tmp-' . bin2hex(random_bytes(6));

        try {
            if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write the temporary install handoff.');
            }

            if (! rename($temporaryPath, $path)) {
                throw new RuntimeException('Unable to replace the install handoff atomically.');
            }

            chmod($path, 0600);
        } catch (Throwable $throwable) {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }

            throw $throwable;
        }

        return $path;
    }
}
