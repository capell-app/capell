<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ProjectBuild;

use Capell\Core\Actions\BuildCapellSiteFromSpecAction;
use Capell\Core\Actions\ValidateSiteSpecAction;
use Capell\Core\Contracts\ProjectBuild\ProjectBuildPackageInstaller;
use Capell\Core\Data\ProjectBuild\ProjectBuildArtifactReferenceData;
use Capell\Core\Data\ProjectBuild\ProjectBuildInstalledPackageData;
use Capell\Core\Data\ProjectBuild\ProjectBuildPackageData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Models\Site;
use Closure;
use Illuminate\Validation\ValidationException;
use JsonException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class InstallProjectBuildManifestAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly ProjectBuildPackageInstaller $packages,
    ) {}

    /** @param Closure(ProjectBuildArtifactReferenceData): string $readArtifact */
    public function handle(string $manifestJson, string $publicKey, Closure $readArtifact, string $platform): Site
    {
        $siteSpecBytes = null;
        $manifest = ValidateProjectBuildManifestBundleAction::run(
            $manifestJson,
            $publicKey,
            static function (ProjectBuildArtifactReferenceData $artifact) use ($readArtifact, &$siteSpecBytes): string {
                $bytes = $readArtifact($artifact);
                if ($artifact->type === 'site-spec') {
                    $siteSpecBytes = $bytes;
                }

                return $bytes;
            },
        );
        VerifyProjectBuildTargetCompatibilityAction::run($manifest->compatibility, $platform);

        $packages = $manifest->packages;
        usort($packages, static fn (ProjectBuildPackageData $left, ProjectBuildPackageData $right): int => $left->installOrder <=> $right->installOrder);

        foreach ($packages as $package) {
            $installed = $this->packages->installedRelease($package->name);
            if (! $this->matches($package, $installed)) {
                $installed = $this->packages->install($package);
            }

            throw_unless($installed instanceof ProjectBuildInstalledPackageData, RuntimeException::class, sprintf(
                'Project build package installer returned no release evidence for [%s].',
                $package->name,
            ));
            $this->assertExactRelease($package, $installed);
        }

        throw_unless(is_string($siteSpecBytes), RuntimeException::class, 'The validated project build bundle did not contain SiteSpec bytes.');

        try {
            $payload = json_decode($siteSpecBytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['siteSpec' => 'The SiteSpec artifact must contain valid JSON.']);
        }

        if (! is_array($payload) || array_is_list($payload)) {
            throw ValidationException::withMessages(['siteSpec' => 'The SiteSpec artifact must contain a JSON object.']);
        }

        $validation = ValidateSiteSpecAction::run($payload, [], [], []);
        if (! $validation['valid'] || ! is_array($validation['normalized'])) {
            throw ValidationException::withMessages($validation['errors']);
        }

        return BuildCapellSiteFromSpecAction::run(CapellSiteSpecData::from($validation['normalized']));
    }

    private function matches(ProjectBuildPackageData $expected, ?ProjectBuildInstalledPackageData $installed): bool
    {
        return $installed instanceof ProjectBuildInstalledPackageData
            && $installed->name === $expected->name
            && hash_equals(ltrim($expected->version, 'v'), ltrim($installed->version, 'v'))
            && hash_equals($expected->releaseIdentity, $installed->releaseIdentity);
    }

    private function assertExactRelease(ProjectBuildPackageData $expected, ProjectBuildInstalledPackageData $installed): void
    {
        throw_unless($installed->name === $expected->name, RuntimeException::class, sprintf(
            'Installed project build package [%s] returned evidence for [%s].',
            $expected->name,
            $installed->name,
        ));
        throw_unless(hash_equals(ltrim($expected->version, 'v'), ltrim($installed->version, 'v')), RuntimeException::class, sprintf(
            'Installed project build package [%s] version does not match the signed manifest.',
            $expected->name,
        ));
        throw_unless(hash_equals($expected->releaseIdentity, $installed->releaseIdentity), RuntimeException::class, sprintf(
            'Installed project build package [%s] release identity does not match the signed manifest.',
            $expected->name,
        ));
    }
}
