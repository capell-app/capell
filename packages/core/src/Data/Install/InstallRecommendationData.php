<?php

declare(strict_types=1);

namespace Capell\Core\Data\Install;

use Capell\Core\Enums\InstallPackageLicenceState;
use Spatie\LaravelData\Data;

final class InstallRecommendationData extends Data
{
    /**
     * `packages` are always installed with the suite. `recommended` entries are pre-ticked and
     * `optional` entries are offered unticked; both map a package name to a one-line reason.
     * `description` must stay true for paths that install only `packages` (the browser installer
     * and `--recommendation`); `suiteDescription` is the richer line the CLI suite flow shows
     * next to its extensions. `packageLicenceStates` separates licence facts from download status.
     * `mayNeedLicence` is retained as the conservative exclusion list for older consumers.
     *
     * @param  list<string>  $packages
     * @param  array<string, string>  $recommended
     * @param  array<string, string>  $optional
     * @param  list<string>  $mayNeedLicence
     * @param  array<string, InstallPackageLicenceState>  $packageLicenceStates
     * @param  list<string>  $downloadedPackages
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly array $packages,
        public readonly ?string $theme = null,
        public readonly ?bool $demo = null,
        public readonly int $order = 0,
        public readonly array $recommended = [],
        public readonly array $optional = [],
        public readonly ?string $suiteDescription = null,
        public readonly array $mayNeedLicence = [],
        public readonly array $packageLicenceStates = [],
        public readonly array $downloadedPackages = [],
    ) {}

    /**
     * Recommended extensions safe to pre-tick: a download that may need a licence would make
     * Composer's dry run, and so the whole install, fail for anyone without paid access.
     *
     * @return list<string>
     */
    public function preselectedRecommended(): array
    {
        if ($this->packageLicenceStates !== []) {
            return array_values(array_filter(array_keys($this->recommended), fn (string $name): bool => in_array($name, $this->downloadedPackages, true)
                || in_array($this->packageLicenceStates[$name] ?? InstallPackageLicenceState::Unknown, [InstallPackageLicenceState::Free, InstallPackageLicenceState::Included], true)));
        }

        return array_values(array_diff(array_keys($this->recommended), $this->mayNeedLicence));
    }

    public function hasExtensions(): bool
    {
        return $this->recommended !== [] || $this->optional !== [];
    }
}
