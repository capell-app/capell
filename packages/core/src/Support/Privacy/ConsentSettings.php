<?php

declare(strict_types=1);

namespace Capell\Core\Support\Privacy;

use Capell\Core\Enums\ConsentCategory;
use Capell\Core\Enums\ConsentDecision;

/**
 * Reads capell.privacy.consent. Anything malformed fails closed: an unknown default is
 * Undecided, and an unknown category or purpose mapping is ignored.
 */
final class ConsentSettings
{
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

    /** @return array<string, string> */
    public function purposes(): array
    {
        $configured = config('capell.privacy.consent.purposes', []);
        $purposes = [];

        foreach (is_array($configured) ? $configured : [] as $purpose => $value) {
            $category = is_string($value) ? ConsentCategory::tryFrom($value) : null;

            if (is_string($purpose) && $purpose !== '' && ConsentCategory::tryFrom($purpose) === null && $category instanceof ConsentCategory) {
                $purposes[$purpose] = $category->value;
            }
        }

        return $purposes;
    }
}
