<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Widgets;

use Capell\Admin\Contracts\Widgets\BlockPickerMetadataProvider;
use Capell\Admin\Data\Widgets\BlockPickerItemMetadataData;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Merges every tagged {@see BlockPickerMetadataProvider} contribution into a
 * single, flat lookup keyed by Filament block name.
 *
 * When two providers contribute metadata for the same block name, the first
 * tagged provider to resolve wins and the later contribution is ignored, so
 * the merge stays deterministic without needing a priority system.
 */
final class ResolveBlockPickerMetadataAction
{
    use AsFake;
    use AsObject;

    /**
     * @return array<string, BlockPickerItemMetadataData>
     */
    public function handle(): array
    {
        $metadata = [];

        foreach (app()->tagged(BlockPickerMetadataProvider::TAG) as $provider) {
            if (! $provider instanceof BlockPickerMetadataProvider) {
                continue;
            }

            foreach ($provider->blockPickerMetadata() as $blockName => $item) {
                if (! is_string($blockName) || $blockName === '') {
                    continue;
                }

                if (! $item instanceof BlockPickerItemMetadataData) {
                    continue;
                }

                if (array_key_exists($blockName, $metadata)) {
                    continue;
                }

                $metadata[$blockName] = $item;
            }
        }

        return $metadata;
    }
}
