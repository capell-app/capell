<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Widgets;

use Capell\Admin\Contracts\Widgets\BlockPickerMetadataProvider;

/**
 * Neutral, optional block-picker presentation metadata for a single Filament
 * block, contributed by a {@see BlockPickerMetadataProvider}.
 *
 * Core has no knowledge of which package (if any) supplies this data. Every
 * field is presentation-only: it never changes the block's stored state,
 * key, or behaviour.
 */
final class BlockPickerItemMetadataData
{
    /**
     * @param  list<string>  $searchTerms
     */
    public function __construct(
        public readonly string $label,
        public readonly string $description = '',
        public readonly string $category = '',
        public readonly ?string $icon = null,
        public readonly array $searchTerms = [],
    ) {}
}
