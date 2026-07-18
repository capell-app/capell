<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Data\Install\InstallHandoffData;
use Capell\Core\Support\Json\JsonCodec;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

final class WriteInstallHandoffAction
{
    use AsFake;
    use AsObject;

    public function handle(InstallHandoffData $handoff, string $path): string
    {
        $path = trim($path);
        $parent = dirname($path);

        throw_if($path === '' || ! is_dir($parent) || ! is_writable($parent), RuntimeException::class, 'Install handoff parent directory must already exist and be writable.');

        throw_if(is_dir($path), RuntimeException::class, 'Install handoff path must identify a JSON file, not a directory.');

        $json = JsonCodec::encode(
            $handoff->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ) . PHP_EOL;
        $temporaryPath = $path . '.tmp-' . bin2hex(random_bytes(6));

        try {
            throw_if(file_put_contents($temporaryPath, $json, LOCK_EX) === false, RuntimeException::class, 'Unable to write the temporary install handoff.');

            throw_unless(rename($temporaryPath, $path), RuntimeException::class, 'Unable to replace the install handoff atomically.');

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
