<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Support\InstallGuide\Patches\EnvQueueConnectionPatch;
use Capell\Installer\Support\InstallGuide\Patches\EnvSettingsCachePatch;
use RuntimeException;

final class InstallerScreenshotFixture
{
    public static function initialize(?string $environmentPath = null): void
    {
        $environmentPath ??= base_path('.env');

        // The generated Testbench host has no .env. Supply real inputs for the
        // install guide probes, preserving an existing host's configuration.
        if (! is_file($environmentPath)) {
            $written = file_put_contents($environmentPath, "APP_ENV=production\nAPP_DEBUG=false\nAPP_URL=https://capell.example\nQUEUE_CONNECTION=sync\nSETTINGS_CACHE_ENABLED=true\n");
            throw_if($written === false, RuntimeException::class, 'Could not prepare the installer screenshot environment.');
        }

        $queue = new EnvQueueConnectionPatch($environmentPath);
        $settings = new EnvSettingsCachePatch($environmentPath);

        throw_if(
            $queue->probe() === PatchStatus::Unsupported || $settings->probe() !== PatchStatus::AlreadyApplied,
            RuntimeException::class,
            'The installer screenshot requires a readable host .env with SETTINGS_CACHE_ENABLED=true; existing configuration was preserved.',
        );
    }
}
