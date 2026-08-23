<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Widgets;

use Capell\Admin\Data\Widgets\BlockPickerItemMetadataData;

/**
 * Neutral, optional extension point that lets any package contribute
 * picker-facing presentation metadata (label, description, category, icon,
 * search terms) for the block-editor block picker, keyed by Filament block
 * name.
 *
 * Core never imports a package class to consume this contract: packages tag
 * their own implementation with {@see self::TAG} from their own service
 * provider, and Core resolves every tagged instance without knowing which
 * package (if any) registered it. A block with no contributed metadata still
 * renders in the picker using its Filament label, icon, and a generic
 * category fallback.
 */
interface BlockPickerMetadataProvider
{
    public const string TAG = 'capell.admin.block_picker_metadata_provider';

    /**
     * @return array<string, BlockPickerItemMetadataData> Keyed by Filament block name.
     */
    public function blockPickerMetadata(): array;
}
