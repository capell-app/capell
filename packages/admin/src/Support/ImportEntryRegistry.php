<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Data\ImportEntryData;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;

/** @extends AbstractKeyedRegistry<ImportEntryData> */
final class ImportEntryRegistry extends AbstractKeyedRegistry
{
    public function register(ImportEntryData $entry): void
    {
        $this->setItem($entry->key, $entry);
    }

    /**
     * @param  class-string  $pageClass
     * @return list<ImportEntryData>
     */
    public function forPage(string $pageClass): array
    {
        return array_values(collect($this->registeredForPage($pageClass))
            ->filter(fn (ImportEntryData $entry): bool => $entry->isVisible())
            ->sort(fn (ImportEntryData $first, ImportEntryData $second): int => [$first->sort, $first->key] <=> [$second->sort, $second->key])
            ->values()
            ->all());
    }

    /**
     * @param  class-string  $pageClass
     * @return list<ImportEntryData>
     */
    public function registeredForPage(string $pageClass): array
    {
        return array_values(collect($this->allItems())
            ->filter(fn (ImportEntryData $entry): bool => $entry->pageClasses === [] || in_array($pageClass, $entry->pageClasses, true))
            ->values()
            ->all());
    }
}
