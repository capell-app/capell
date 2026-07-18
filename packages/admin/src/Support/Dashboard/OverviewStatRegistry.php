<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Dashboard;

use Capell\Admin\Data\Dashboard\CapellOverviewStatData;
use Capell\Admin\Data\Dashboard\CapellOverviewStatDefinitionData;
use Capell\Admin\Settings\AdminSettings;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Throwable;

/** @extends AbstractKeyedRegistry<CapellOverviewStatDefinitionData> */
class OverviewStatRegistry extends AbstractKeyedRegistry
{
    public function register(CapellOverviewStatDefinitionData $stat): void
    {
        if ($stat->key !== '' && ! $this->hasItem($stat->key)) {
            $this->setItem($stat->key, $stat);
        }
    }

    /** @return list<CapellOverviewStatData> */
    public function resolved(bool $onlyEnabled = true): array
    {
        return array_values(collect($this->allItems())
            ->filter(fn (CapellOverviewStatDefinitionData $stat): bool => ! $onlyEnabled || $this->isEnabled($stat))
            ->map(fn (CapellOverviewStatDefinitionData $stat): CapellOverviewStatData => $stat->resolve())
            ->sortBy([['sort', 'asc'], ['group', 'asc'], ['label', 'asc']])
            ->values()->all());
    }

    /** @return list<array{key: string, label: string, group: string, description?: string|null}> */
    public function settings(): array
    {
        return array_values(collect($this->allItems())->map(
            fn (CapellOverviewStatDefinitionData $stat): array => $stat->settingsEntry(),
        )->values()->all());
    }

    /** @return list<string> */
    public function defaultEnabledKeys(): array
    {
        return $this->keys(true);
    }

    /** @return list<string> */
    public function keys(bool $onlyDefaultEnabled = false): array
    {
        return array_values(collect($this->allItems())
            ->filter(fn (CapellOverviewStatDefinitionData $stat): bool => ! $onlyDefaultEnabled || $stat->defaultEnabled)
            ->map(fn (CapellOverviewStatDefinitionData $stat): string => $stat->settingsKey())
            ->unique()->values()->all());
    }

    private function isEnabled(CapellOverviewStatDefinitionData $stat): bool
    {
        try {
            return resolve(AdminSettings::class)->isWidgetEnabled($stat->settingsKey());
        } catch (Throwable) {
            return $stat->defaultEnabled;
        }
    }
}
