<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install\Cli;

use Capell\Core\Actions\GetPluginsAction;
use Capell\Core\Data\Install\InstallRecommendationData;
use Capell\Core\Data\Install\InstallSuiteSelectionData;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\InstallRecommendationRepository;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multisearch;
use function Laravel\Prompts\multiselect;
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

    /** Returns null when no suite fits, so the caller falls back to the plain package checklist. */
    public function prompt(): ?InstallSuiteSelectionData
    {
        $suites = collect($this->suites->all())->keyBy(fn (InstallRecommendationData $suite): string => $suite->key);

        if ($suites->isEmpty()) {
            return null;
        }

        $suiteKey = (string) select(
            label: __('capell-core::install.suites.goal_label'),
            options: [
                ...$suites->map(fn (InstallRecommendationData $suite): string => $suite->label . ' — ' . $suite->description)->all(),
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
            ...$suite->packages,
            ...$this->tick(
                $suite->recommended,
                __('capell-core::install.suites.recommended_label', ['suite' => $suite->label]),
                __('capell-core::install.suites.recommended_hint'),
                preselected: true,
            ),
            ...$this->tick(
                $suite->optional,
                __('capell-core::install.suites.optional_label', ['suite' => $suite->label]),
                __('capell-core::install.suites.optional_hint'),
                preselected: false,
            ),
        ];

        if (confirm(label: __('capell-core::install.suites.search_confirm'), default: false)) {
            $selected = [...$selected, ...$this->search($selected)];
        }

        return new InstallSuiteSelectionData(
            suiteKey: $suite->key,
            packages: array_values(array_unique($selected)),
            theme: $suite->theme,
            demo: $suite->demo,
        );
    }

    /**
     * @param  array<string, string>  $reasons
     * @return list<string>
     */
    private function tick(array $reasons, string $label, string $hint, bool $preselected): array
    {
        if ($reasons === []) {
            return [];
        }

        $options = [];
        foreach ($reasons as $package => $reason) {
            $options[$package] = $this->displayName($package) . ($reason === '' ? '' : ' — ' . $reason);
        }

        return array_values(array_map(strval(...), multiselect(
            label: $label,
            options: $options,
            default: $preselected ? array_keys($reasons) : [],
            hint: $hint,
        )));
    }

    /**
     * @param  list<string>  $alreadySelected
     * @return list<string>
     */
    private function search(array $alreadySelected): array
    {
        $candidates = $this->searchableExtensions($alreadySelected);

        if ($candidates->isEmpty()) {
            return [];
        }

        return array_values(array_map(strval(...), multisearch(
            label: __('capell-core::install.suites.search_label'),
            options: fn (string $typed): array => $this->matching($candidates, $typed),
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
     * Everything installable that is not a theme, a foundation package or already chosen.
     *
     * @param  list<string>  $alreadySelected
     * @return Collection<string, PackageData>
     */
    private function searchableExtensions(array $alreadySelected): Collection
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
            ->reject(fn (PackageData $package): bool => $package->isInstalled())
            ->reject(fn (PackageData $package): bool => in_array($package->name, $alreadySelected, true))
            ->sortBy(fn (PackageData $package): string => $package->getLabel());
    }

    private function describe(PackageData $package): string
    {
        $description = trim((string) $package->getDescription());

        return $description === ''
            ? $package->getLabel()
            : $package->getLabel() . ' — ' . Str::limit($description, 80);
    }

    private function displayName(string $package): string
    {
        return CapellCore::hasPackage($package)
            ? CapellCore::getPackage($package)->getLabel()
            : Str::of($package)->afterLast('/')->replace('-', ' ')->title()->toString();
    }
}
