<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ProjectBuild;

use Capell\Core\Contracts\ProjectBuild\ProjectBuildPackageInstaller;
use Capell\Core\Data\ProjectBuild\ProjectBuildCompatibilityData;
use Capell\Core\Data\ProjectBuild\ProjectBuildInstalledPackageData;
use Composer\Semver\Semver;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

final class VerifyProjectBuildTargetCompatibilityAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly ProjectBuildPackageInstaller $packages,
    ) {}

    public function handle(ProjectBuildCompatibilityData $compatibility, string $platform): void
    {
        throw_unless(in_array($platform, $compatibility->platforms, true), RuntimeException::class, sprintf(
            'Project build manifest platform [%s] is incompatible with this target.',
            $platform,
        ));

        throw_unless($this->satisfies(PHP_VERSION, $compatibility->php), RuntimeException::class, sprintf(
            'Project build manifest PHP constraint [%s] is incompatible with PHP [%s].',
            $compatibility->php,
            PHP_VERSION,
        ));

        $core = $this->packages->installedRelease('capell-app/core');
        throw_unless(
            $core instanceof ProjectBuildInstalledPackageData
                && $this->satisfies(ltrim($core->version, 'v'), $compatibility->capell),
            RuntimeException::class,
            sprintf('Project build manifest Capell constraint [%s] is incompatible with this target.', $compatibility->capell),
        );
    }

    private function satisfies(string $version, string $constraint): bool
    {
        try {
            return Semver::satisfies($version, $constraint);
        } catch (Throwable) {
            return false;
        }
    }
}
