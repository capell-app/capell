<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Extensions;

use Capell\Admin\Data\ExtensionManagementEntryData;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class FilterExtensionManagementEntriesAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  list<ExtensionManagementEntryData>  $entries
     * @return list<ExtensionManagementEntryData>
     */
    public function handle(
        array $entries,
        ?string $search = null,
        ?string $productGroup = null,
        bool $installedOnly = false,
        ?string $installedStatus = 'all',
        ?string $price = null,
        ?string $health = null,
        ?string $sort = 'latest',
    ): array {
        $installedStatus = $this->installedStatus($installedStatus, $installedOnly);

        return array_values(collect($entries)
            ->filter(fn (ExtensionManagementEntryData $entry): bool => $this->matchesSearch($entry, (string) $search))
            ->filter(fn (ExtensionManagementEntryData $entry): bool => $this->matchesInstalledStatus($entry, $installedStatus))
            ->filter(fn (ExtensionManagementEntryData $entry): bool => $productGroup === null || $productGroup === '' || $entry->productGroup === $productGroup)
            ->filter(fn (ExtensionManagementEntryData $entry): bool => $this->matchesPrice($entry, $price))
            ->filter(fn (ExtensionManagementEntryData $entry): bool => $this->matchesHealth($entry, $health))
            ->sort(fn (ExtensionManagementEntryData $first, ExtensionManagementEntryData $second): int => $this->sortEntries($first, $second, $sort))
            ->values()
            ->all());
    }

    private function installedStatus(?string $installedStatus, bool $installedOnly): string
    {
        if ($installedOnly) {
            return 'installed';
        }

        return in_array($installedStatus, ['installed', 'uninstalled'], true)
            ? $installedStatus
            : 'all';
    }

    private function matchesInstalledStatus(ExtensionManagementEntryData $entry, string $installedStatus): bool
    {
        return match ($installedStatus) {
            'installed' => $entry->installed,
            'uninstalled' => ! $entry->installed,
            default => true,
        };
    }

    private function matchesSearch(ExtensionManagementEntryData $entry, string $search): bool
    {
        if (trim($search) === '') {
            return true;
        }

        $haystack = collect($this->searchableValues($entry))
            ->filter(fn (?string $value): bool => $value !== null && $value !== '')
            ->map(fn (string $value): string => mb_strtolower($value))
            ->implode(' ');

        return collect(str_getcsv(mb_strtolower($search), separator: ' ', escape: '\\'))
            ->filter(fn (?string $term): bool => is_string($term) && trim($term) !== '')
            ->every(fn (?string $term): bool => is_string($term) && str_contains($haystack, trim($term)));
    }

    /**
     * @return list<string|null>
     */
    private function searchableValues(ExtensionManagementEntryData $entry): array
    {
        return [
            $entry->label,
            $this->normaliseSearchValue($entry->label),
            $entry->packageName,
            $this->normaliseSearchValue($entry->packageName),
            str($entry->packageName)->afterLast('/')->toString(),
            $entry->description,
            $entry->version,
            $entry->latestVersion,
            $entry->tier,
            $entry->certification,
            $entry->runtimeStatus,
            $entry->healthState,
            $entry->productGroup,
        ];
    }

    private function normaliseSearchValue(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return str($value)
            ->replace(['/', '-', '_'], ' ')
            ->squish()
            ->toString();
    }

    private function matchesPrice(ExtensionManagementEntryData $entry, ?string $price): bool
    {
        return match ($price) {
            'free' => $entry->tier !== 'premium',
            'paid' => $entry->tier === 'premium',
            default => true,
        };
    }

    private function matchesHealth(ExtensionManagementEntryData $entry, ?string $health): bool
    {
        return match ($health) {
            'ok', 'warning', 'critical' => $entry->healthState === $health,
            default => true,
        };
    }

    private function sortEntries(ExtensionManagementEntryData $first, ExtensionManagementEntryData $second, ?string $sort): int
    {
        return match ($sort) {
            'name' => [mb_strtolower($first->label), mb_strtolower($first->packageName)]
                <=> [mb_strtolower($second->label), mb_strtolower($second->packageName)],
            'name_desc' => [mb_strtolower($second->label), mb_strtolower($second->packageName)]
                <=> [mb_strtolower($first->label), mb_strtolower($first->packageName)],
            default => $this->sortByLatestActivity($first, $second),
        };
    }

    private function sortByLatestActivity(ExtensionManagementEntryData $first, ExtensionManagementEntryData $second): int
    {
        $firstTimestamp = max(
            $first->updatedAt?->getTimestamp() ?? PHP_INT_MIN,
            $first->installedAt?->getTimestamp() ?? PHP_INT_MIN,
        );
        $secondTimestamp = max(
            $second->updatedAt?->getTimestamp() ?? PHP_INT_MIN,
            $second->installedAt?->getTimestamp() ?? PHP_INT_MIN,
        );

        if ($firstTimestamp !== $secondTimestamp) {
            return $secondTimestamp <=> $firstTimestamp;
        }

        return [mb_strtolower($first->label), mb_strtolower($first->packageName)]
            <=> [mb_strtolower($second->label), mb_strtolower($second->packageName)];
    }
}
