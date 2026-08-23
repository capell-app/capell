<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Widgets;

use Capell\Admin\Contracts\Widgets\BlockPickerMetadataProvider;
use Capell\Admin\Data\Widgets\BlockPickerItemMetadataData;

/**
 * Contributes picker metadata for the real, always-registered `content`
 * block so integration tests can prove a tagged provider's metadata reaches
 * the rendered picker without depending on an installed package.
 */
final class ContentBlockPickerMetadataProvider implements BlockPickerMetadataProvider
{
    /**
     * @return array<string, BlockPickerItemMetadataData>
     */
    public function blockPickerMetadata(): array
    {
        return [
            'content' => new BlockPickerItemMetadataData(
                label: 'Rich content',
                description: 'Freeform prose and inline media.',
                category: 'Foundation blocks',
                icon: 'heroicon-o-document-text',
                searchTerms: ['prose', 'paragraph'],
            ),
        ];
    }
}
