<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Widgets;

use Capell\Admin\Data\Widgets\BlockPickerItemMetadataData;
use Capell\Admin\Data\Widgets\BlockPickerItemViewData;

/**
 * Turns a Filament block plus its optional contributed metadata into a
 * fallback-safe {@see BlockPickerItemViewData}, and groups resolved items
 * into ordered, labelled categories for the block picker.
 *
 * Pure and Filament-`Block`-free by design: every input is a plain scalar,
 * so both methods are unit testable without booting a form.
 */
final class BlockPickerItemPresenter
{
    public function present(
        string $blockName,
        string $filamentLabel,
        ?string $filamentIcon,
        ?BlockPickerItemMetadataData $metadata,
        string $wireClickAction,
        string $fallbackCategory,
        string $fallbackIcon,
    ): BlockPickerItemViewData {
        if (! $metadata instanceof BlockPickerItemMetadataData) {
            $label = $filamentLabel;
            $description = '';
            $category = $fallbackCategory;
            $icon = $filamentIcon ?? $fallbackIcon;
            $searchTerms = [];
        } else {
            $label = filled($metadata->label) ? $metadata->label : $filamentLabel;
            $description = $metadata->description;
            $category = filled($metadata->category) ? $metadata->category : $fallbackCategory;
            $icon = $metadata->icon ?? $filamentIcon ?? $fallbackIcon;
            $searchTerms = $metadata->searchTerms;
        }

        $haystackParts = [$label, $description, $category, ...$searchTerms];

        return new BlockPickerItemViewData(
            key: $blockName,
            label: $label,
            description: $description,
            category: $category,
            icon: $icon,
            searchHaystack: mb_strtolower(implode(' ', array_filter($haystackParts, filled(...)))),
            wireClickAction: $wireClickAction,
        );
    }

    /**
     * @param  list<BlockPickerItemViewData>  $items
     * @return array<string, list<BlockPickerItemViewData>> Category label => items, both sorted alphabetically. The fallback category always sorts last.
     */
    public function group(array $items, string $fallbackCategory): array
    {
        $grouped = [];

        foreach ($items as $item) {
            $grouped[$item->category][] = $item;
        }

        foreach ($grouped as $category => $categoryItems) {
            usort(
                $categoryItems,
                static fn (BlockPickerItemViewData $first, BlockPickerItemViewData $second): int => $first->label <=> $second->label,
            );

            $grouped[$category] = $categoryItems;
        }

        uksort(
            $grouped,
            static fn (string $first, string $second): int => match (true) {
                $first === $second => 0,
                $first === $fallbackCategory => 1,
                $second === $fallbackCategory => -1,
                default => $first <=> $second,
            },
        );

        return $grouped;
    }
}
