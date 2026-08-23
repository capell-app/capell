<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Widgets;

use Capell\Admin\Contracts\Widgets\BlockPickerMetadataProvider;
use Capell\Admin\Data\Widgets\BlockPickerItemMetadataData;

final class SecondStubBlockPickerMetadataProvider implements BlockPickerMetadataProvider
{
    /**
     * @return array<string, BlockPickerItemMetadataData>
     */
    public function blockPickerMetadata(): array
    {
        return [
            'hero' => new BlockPickerItemMetadataData(
                label: 'Hero (duplicate)',
                description: 'This contribution should be ignored.',
                category: 'Duplicate',
            ),
            'testimonial' => new BlockPickerItemMetadataData(
                label: 'Testimonial',
                description: 'A quote from a customer.',
                category: 'Social proof',
            ),
        ];
    }
}
