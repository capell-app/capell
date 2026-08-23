<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Widgets;

use Capell\Admin\Contracts\Widgets\BlockPickerMetadataProvider;
use Capell\Admin\Data\Widgets\BlockPickerItemMetadataData;

final class FirstStubBlockPickerMetadataProvider implements BlockPickerMetadataProvider
{
    /**
     * @return array<string, BlockPickerItemMetadataData>
     */
    public function blockPickerMetadata(): array
    {
        return [
            'hero' => new BlockPickerItemMetadataData(
                label: 'Hero',
                description: 'A large intro banner.',
                category: 'Foundation',
                icon: 'heroicon-o-photo',
                searchTerms: ['banner', 'intro'],
            ),
        ];
    }
}
