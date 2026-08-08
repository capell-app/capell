<?php

declare(strict_types=1);

use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Support\Packages\PackageLifecycleRunner;

/**
 * Exercises the fresh-process command probe against the real process
 * factory and a real `php artisan list --raw --no-interaction`, rather than
 * a mocked Process. The unit tests cover the probe's decision logic in
 * isolation; this confirms the actual skeleton artisan still produces
 * output the probe can parse.
 */
it('throws does-not-exist for a genuinely unregistered command run through a real fresh process', function (): void {
    $package = new PackageData(
        name: 'vendor/integration-probe-package',
        type: PackageTypeEnum::Plugin,
    );

    resolve(PackageLifecycleRunner::class)->run(
        package: $package,
        phase: 'install',
        command: 'capell:integration-probe-nonexistent-command',
        actionClass: null,
        allowLegacyCommand: true,
        freshProcess: true,
    );
})->throws(RuntimeException::class, "Install command 'capell:integration-probe-nonexistent-command' does not exist.");
