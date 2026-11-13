<?php

declare(strict_types=1);

namespace Capell\Core\Support\Privacy;

use Capell\Core\Enums\ConsentCategory;
use Capell\Core\Enums\ConsentDecision;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Manifest\CapellManifestData;

/**
 * Reads capell.privacy.consent. Anything malformed fails closed: an unknown default is
 * Undecided, and an unknown category or purpose mapping is ignored.
 */
final class ConsentSettings
{
    /** @var array<string, string>|null */
    private ?array $packagePurposes = null;

    public function runtimeEnabled(): bool
    {
        return filter_var(config('capell.privacy.consent.runtime', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) !== false;
    }

    /**
     * Whether Capell's own counter records aggregate page views while analytics is undecided.
     * It stores no cookie and no visitor identifier; a refusal still stops it.
     */
    public function firstPartyCounterEnabled(): bool
    {
        return filter_var(config('capell.privacy.first_party_counter', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) !== false;
    }

    /** What a category resolves to when no consent manager has recorded a choice. */
    public function defaultDecision(ConsentCategory $category): ConsentDecision
    {
        if ($category === ConsentCategory::Essential) {
            return ConsentDecision::Granted;
        }

        $configured = config('capell.privacy.consent.defaults.' . $category->value);

        return is_string($configured)
            ? ConsentDecision::tryFrom($configured) ?? ConsentDecision::Undecided
            : ConsentDecision::Undecided;
    }

    /**
     * Categories a Global Privacy Control or Do Not Track signal refuses. Essential can never be refused.
     *
     * @return list<ConsentCategory>
     */
    public function optOutSignalCategories(): array
    {
        $configured = config('capell.privacy.consent.opt_out_signal_categories', [ConsentCategory::Analytics->value, ConsentCategory::Marketing->value]);
        $categories = [];

        foreach (is_array($configured) ? $configured : [] as $value) {
            $category = is_string($value) ? ConsentCategory::tryFrom($value) : null;

            if ($category instanceof ConsentCategory && $category !== ConsentCategory::Essential) {
                $categories[$category->value] = $category;
            }
        }

        return array_values($categories);
    }

    /**
     * Named purposes mapped to their category: those enabled packages declare in
     * capell.json privacy.purposes, then capell.privacy.consent.purposes, which wins
     * so a site can move a purpose to another category. When two packages declare
     * one purpose differently, the stricter category is kept. The package walk
     * runs once per instance, since a page can ask for every purpose it gates;
     * configuration is read on each call.
     *
     * @return array<string, string>
     */
    public function purposes(): array
    {
        $purposes = $this->packagePurposes ??= $this->resolvePackagePurposes();

        return $this->withConfiguredPurposes($purposes);
    }

    /**
     * @param  array<string, string>  $purposes
     * @return array<string, string>
     */
    private function withConfiguredPurposes(array $purposes): array
    {
        $configured = config('capell.privacy.consent.purposes', []);

        foreach (is_array($configured) ? $configured : [] as $purpose => $value) {
            $category = is_string($value) ? ConsentCategory::tryFrom($value) : null;

            if (is_string($purpose) && $purpose !== '' && ConsentCategory::tryFrom($purpose) === null && $category instanceof ConsentCategory) {
                $purposes[$purpose] = $category->value;
            }
        }

        return $purposes;
    }

    /** @return array<string, string> */
    private function resolvePackagePurposes(): array
    {
        $purposes = [];

        foreach (CapellCore::getPackages(withoutCore: false) as $package) {
            if (! $package->manifest instanceof CapellManifestData) {
                continue;
            }

            if ($package->manifest->privacyPurposes === []) {
                continue;
            }

            if (! CapellCore::isPackageEnabled($package->name)) {
                continue;
            }

            foreach ($package->manifest->privacyPurposes as $purpose => $value) {
                $category = ConsentCategory::tryFrom($value);

                if (! $category instanceof ConsentCategory) {
                    continue;
                }

                if ($category === ConsentCategory::Essential) {
                    continue;
                }

                if (ConsentCategory::tryFrom($purpose) !== null) {
                    continue;
                }

                $current = isset($purposes[$purpose]) ? ConsentCategory::from($purposes[$purpose]) : null;

                if (! $current instanceof ConsentCategory || $this->strictness($category) > $this->strictness($current)) {
                    $purposes[$purpose] = $category->value;
                }
            }
        }

        return $purposes;
    }

    /** Later cases in ConsentCategory need more care before they load. */
    private function strictness(ConsentCategory $category): int
    {
        return (int) array_search($category, ConsentCategory::cases(), true);
    }
}
