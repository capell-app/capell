<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Widgets;

use Capell\Admin\Contracts\Widgets\BlockPickerMetadataProvider;
use Capell\Admin\Data\Widgets\BlockPickerItemMetadataData;

/**
 * Contributes a second, competing metadata entry for the `content` block so
 * integration tests can prove a duplicate contribution never reaches the
 * rendered picker.
 */
final class DuplicateContentBlockPickerMetadataProvider implements BlockPickerMetadataProvider
{
    /**
     * @return array<string, BlockPickerItemMetadataData>
     */
    public function blockPickerMetadata(): array
    {
        return [
            'content' => new BlockPickerItemMetadataData(
                label: 'Rich content (duplicate)',
                description: 'This duplicate description must never render.',
                category: 'Duplicate blocks',
            ),
        ];
    }
}
