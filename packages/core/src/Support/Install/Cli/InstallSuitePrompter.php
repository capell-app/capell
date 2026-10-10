<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install\Cli;

use Capell\Core\Actions\GetPluginsAction;
use Capell\Core\Actions\Install\CheckInstallPackageAccessAction;
use Capell\Core\Actions\Install\ResolveInstallPackageLicenceAction;
use Capell\Core\Data\Install\InstallRecommendationData;
use Capell\Core\Data\Install\InstallSuiteSelectionData;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\ExtensionLicenceStatus;
use Capell\Core\Enums\InstallPackageLicenceState;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\InstallRecommendationRepository;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multisearch;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;

use Throwable;

/**
 * Guides a newcomer from "what are you building?" to a package list: a suite fixes the foundation,
 * then recommended and optional extensions are ticked, and a search reaches everything else.
 */
final class InstallSuitePrompter
{
    private const string CUSTOM_KEY = '__custom';

    private const int SEARCH_RESULT_LIMIT = 12;

    public function __construct(private readonly InstallRecommendationRepository $suites) {}

    /**
     * Returns null when no suite fits, so the caller falls back to the plain package checklist.
     * A fresh install wipes the current install state, so installed extensions stay searchable.
     */
    public function prompt(bool $freshInstall = false, ?string $siteUrl = null): ?InstallSuiteSelectionData
    {
        $suites = collect($this->suites->suites())->keyBy(fn (InstallRecommendationData $suite): string => $suite->key);

        if ($suites->isEmpty()) {
            return null;
        }

        $suiteKey = (string) select(
            label: __('capell-core::install.suites.goal_label'),
            options: [
                ...$suites->map(fn (InstallRecommendationData $suite): string => $suite->label . ' — ' . $this->suiteDescription($suite))->all(),
                self::CUSTOM_KEY => __('capell-core::install.suites.custom_option'),
            ],
            default: (string) $suites->keys()->first(),
            hint: __('capell-core::install.suites.goal_hint'),
        );

        if ($suiteKey === self::CUSTOM_KEY || ! $suites->has($suiteKey)) {
            return null;
        }

        /** @var InstallRecommendationData $suite */
        $suite = $suites->get($suiteKey);

        $selected = [
            ...$this->foundationPackages($suite->packages),
            ...$this->tick(
                $suite->recommended,
                __('capell-core::install.suites.recommended_label', ['suite' => $suite->label]),
                __('capell-core::install.suites.recommended_hint'),
                preselected: $suite->preselectedRecommended(),
                licenceStates: $suite->packageLicenceStates,
                downloadedPackages: $suite->downloadedPackages,
            ),
            ...$this->tick(
                $suite->optional,
                __('capell-core::install.suites.optional_label', ['suite' => $suite->label]),
                __('capell-core::install.suites.optional_hint'),
                preselected: [],
                licenceStates: $suite->packageLicenceStates,
                downloadedPackages: $suite->downloadedPackages,
            ),
        ];

        if (confirm(label: __('capell-core::install.suites.search_confirm'), default: false)) {
            $selected = [...$selected, ...$this->search($selected, $freshInstall)];
        }

        return new InstallSuiteSelectionData(
            packages: $this->reviewDownloadAccess(array_values(array_unique($selected)), $siteUrl ?? (string) config('app.url')),
            theme: $suite->theme,
            demo: $suite->demo,
        );
    }

    /**
     * The suite's packages plus the defaults the plain checklist pre-ticks that build on them, so
     * choosing a suite never drops the Marketplace or the welcome tour. A default joins only when
     * the suite installs everything it needs, so a suite without the admin (headless) gets none.
     *
     * @param  list<string>  $suitePackages
     * @return list<string>
     */
    public function foundationPackages(array $suitePackages): array
    {
        $defaults = CapellCore::getPackages(sortByDependencies: true)
            ->filter(fn (PackageData $package): bool => TrustedCorePackages::isDefaultInstallSelection($package->name)
                && $package->isVisibleInCatalogue()
                && ! in_array($package->name, $suitePackages, true))
            ->filter(function (PackageData $package) use ($suitePackages): bool {
                $requirements = array_values(array_filter(
                    $package->getRequirements(),
                    fn (string $requirement): bool => ! TrustedCorePackages::isCoreRuntimePackage($requirement),
                ));

                return $requirements !== [] && array_diff($requirements, $suitePackages) === [];
            })
            ->keys()
            ->map(fn (int|string $packageName): string => (string) $packageName)
            ->all();

        return array_values(array_unique([...$suitePackages, ...$defaults]));
    }

    /** The richer CLI line only when this run can offer the extensions it describes. */
    private function suiteDescription(InstallRecommendationData $suite): string
    {
        return $suite->suiteDescription !== null && $suite->hasExtensions()
            ? $suite->suiteDescription
            : $suite->description;
    }

    /**
     * @param  array<string, string>  $reasons
     * @param  list<string>  $preselected
     * @param  array<string, InstallPackageLicenceState>  $licenceStates
     * @param  list<string>  $downloadedPackages
     * @return list<string>
     */
    private function tick(array $reasons, string $label, string $hint, array $preselected, array $licenceStates, array $downloadedPackages): array
    {
        if ($reasons === []) {
            return [];
        }

        $options = [];
        foreach ($reasons as $package => $reason) {
            $options[$package] = $this->displayName($package)
                . ($reason === '' ? '' : ' — ' . $reason)
                . ' [' . ($licenceStates[$package] ?? InstallPackageLicenceState::Unknown)->label()
                . (in_array($package, $downloadedPackages, true) ? '; ' . __('capell-core::install.licence.downloaded') : '') . ']';
        }

        return array_values(array_map(strval(...), multiselect(
            label: $label,
            options: $options,
            default: array_values(array_intersect($preselected, array_keys($reasons))),
            hint: $hint,
        )));
    }

    /**
     * @param  list<string>  $alreadySelected
     * @return list<string>
     */
    private function search(array $alreadySelected, bool $freshInstall): array
    {
        $candidates = $this->searchableExtensions($alreadySelected, $freshInstall);

        if ($candidates->isEmpty()) {
            note(__('capell-core::install.suites.nothing_to_search'));

            return [];
        }

        return array_values(array_map(strval(...), multisearch(
            label: __('capell-core::install.suites.search_label'),
            // The non-interactive fallback (Windows) passes null for an empty answer.
            options: fn (?string $typed): array => $this->matching($candidates, $typed ?? ''),
            placeholder: __('capell-core::install.suites.search_placeholder'),
            hint: __('capell-core::install.suites.search_hint'),
        )));
    }

    /**
     * @param  Collection<string, PackageData>  $candidates
     * @return array<string, string>
     */
    private function matching(Collection $candidates, string $typed): array
    {
        $needle = Str::lower(trim($typed));

        return $candidates
            ->filter(fn (PackageData $package): bool => $needle === ''
                || str_contains(Str::lower($package->name . ' ' . $package->getLabel() . ' ' . $package->getDescription()), $needle))
            ->take(self::SEARCH_RESULT_LIMIT)
            ->map(fn (PackageData $package): string => $this->describe($package))
            ->all();
    }

    /**
     * Everything installable that is not a theme, a foundation package or already chosen. Installed
     * extensions are hidden, except on a fresh install, which wipes that state and reinstalls.
     *
     * @param  list<string>  $alreadySelected
     * @return Collection<string, PackageData>
     */
    private function searchableExtensions(array $alreadySelected, bool $freshInstall): Collection
    {
        try {
            $downloadable = GetPluginsAction::run('download');
        } catch (Throwable) {
            $downloadable = collect();
        }

        return CapellCore::getPackages()
            ->merge($downloadable)
            ->filter(fn (PackageData $package): bool => $package->isVisibleInCatalogue())
            ->reject(fn (PackageData $package): bool => $package->getThemeKey() !== null)
            ->reject(fn (PackageData $package): bool => TrustedCorePackages::contains($package->name))
            ->reject(fn (PackageData $package): bool => ! $freshInstall && $package->isInstalled())
            ->reject(fn (PackageData $package): bool => in_array($package->name, $alreadySelected, true))
            ->sortBy(fn (PackageData $package): string => $package->getLabel());
    }

    private function describe(PackageData $package): string
    {
        $description = trim((string) $package->getDescription());
        $note = ' [' . ResolveInstallPackageLicenceAction::run($package)->label()
            . (CapellCore::hasPackage($package->name) ? '; ' . __('capell-core::install.licence.downloaded') : '') . ']';

        return ($description === ''
            ? $package->getLabel()
            : $package->getLabel() . ' — ' . Str::limit($description, 80)) . $note;
    }

    /** @param list<string> $selected
     * @return list<string>
     */
    private function reviewDownloadAccess(array $selected, string $siteUrl): array
    {
        try {
            $catalogue = GetPluginsAction::run('download');
        } catch (Throwable) {
            $catalogue = collect();
        }

        foreach ($selected as $index => $name) {
            if (CapellCore::hasPackage($name)) {
                continue;
            }

            if (TrustedCorePackages::contains($name)) {
                continue;
            }

            $package = $catalogue->get($name);
            if (! $package instanceof PackageData) {
                continue;
            }

            $state = ResolveInstallPackageLicenceAction::run($package);
            if ($state === InstallPackageLicenceState::Free) {
                continue;
            }

            if ($state === InstallPackageLicenceState::Unavailable) {
                note($this->displayName($name) . ': ' . $state->label());
                unset($selected[$index]);

                continue;
            }

            do {
                $decision = $state === InstallPackageLicenceState::Required
                    ? CheckInstallPackageAccessAction::run($package, $siteUrl) : null;
                if ($decision?->canDownload && $decision->canInstall
                    && in_array($decision->licenceStatus, [ExtensionLicenceStatus::Active, ExtensionLicenceStatus::Purchased], true)) {
                    note($this->displayName($name) . ': ' . InstallPackageLicenceState::Included->label());
                    break;
                }

                note($this->displayName($name) . ': ' . $state->label());
                note(match (true) {
                    $state === InstallPackageLicenceState::Unknown => __('capell-core::install.licence.metadata_unverified'),
                    $decision === null => __('capell-core::install.licence.access_unverified'),
                    default => __('capell-core::install.licence.access_denied', ['site' => $siteUrl]),
                });
                if ($package->purchaseUrl !== null && filter_var($package->purchaseUrl, FILTER_VALIDATE_URL) !== false
                    && parse_url($package->purchaseUrl, PHP_URL_SCHEME) === 'https') {
                    note(__('capell-core::install.licence.purchase', ['url' => $package->purchaseUrl]));
                }

                $options = [
                    'remove' => __('capell-core::install.licence.remove'),
                    'retry' => __('capell-core::install.licence.retry'),
                ];
                if ($decision === null) {
                    $options['composer'] = __('capell-core::install.licence.check_composer');
                }

                $choice = select(label: __('capell-core::install.licence.access_label', ['package' => $this->displayName($name)]), options: $options, default: 'remove');
            } while ($choice === 'retry');

            if (isset($choice) && $choice === 'remove') {
                unset($selected[$index]);
            }

            unset($choice);
        }

        return array_values($selected);
    }

    private function displayName(string $package): string
    {
        return CapellCore::hasPackage($package)
            ? CapellCore::getPackage($package)->getLabel()
            : Str::of($package)->afterLast('/')->replace('-', ' ')->title()->toString();
    }
}
