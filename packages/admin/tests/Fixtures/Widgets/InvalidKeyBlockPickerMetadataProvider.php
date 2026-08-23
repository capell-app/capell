<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Widgets;

use Capell\Admin\Contracts\Widgets\BlockPickerMetadataProvider;
use Capell\Admin\Data\Widgets\BlockPickerItemMetadataData;

final class InvalidKeyBlockPickerMetadataProvider implements BlockPickerMetadataProvider
{
    /**
     * @return array<string, BlockPickerItemMetadataData>
     */
    public function blockPickerMetadata(): array
    {
        return [
            '' => new BlockPickerItemMetadataData(label: 'Should be ignored'),
        ];
    }
}
