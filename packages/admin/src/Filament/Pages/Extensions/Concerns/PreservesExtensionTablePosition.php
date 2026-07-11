<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Extensions\Concerns;

trait PreservesExtensionTablePosition
{
    public ?int $extensionTablePinnedIndex = null;

    public ?string $extensionTablePinnedPackageName = null;

    public function rememberCurrentExtensionTablePosition(string $packageName): void
    {
        $this->extensionTablePinnedPackageName = $packageName;
        $this->extensionTablePinnedIndex = null;

        $records = $this->getTableRecords();
        $items = method_exists($records, 'items') ? $records->items() : [];

        foreach ($items as $index => $record) {
            if (! is_array($record)) {
                continue;
            }

            if (($record['packageName'] ?? null) !== $packageName) {
                continue;
            }

            $this->extensionTablePinnedIndex = (int) $index;

            return;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    protected function applyPinnedExtensionTablePosition(array $records): array
    {
        if ($this->extensionTablePinnedPackageName === null || $this->extensionTablePinnedIndex === null) {
            return $records;
        }

        $currentIndex = null;

        foreach ($records as $index => $record) {
            if (($record['packageName'] ?? null) !== $this->extensionTablePinnedPackageName) {
                continue;
            }

            $currentIndex = $index;

            break;
        }

        if ($currentIndex === null) {
            return $records;
        }

        $record = $records[$currentIndex];

        array_splice($records, $currentIndex, 1);
        array_splice($records, min($this->extensionTablePinnedIndex, count($records)), 0, [$record]);

        return array_values($records);
    }
}
