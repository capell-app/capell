<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Media;

use Capell\Admin\Data\Media\MediaHealthStateData;
use Capell\Admin\Support\MediaScope;
use Capell\Admin\Support\Media\MediaDuplicateIndex;
use Capell\Core\Models\Media;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildMediaHealthStateAction
{
    use AsFake;
    use AsObject;

    public function handle(Media $media): MediaHealthStateData
    {
        $metadata = $media->localizedMetadata();
        $usageCount = $this->usageCount($media);

        return new MediaHealthStateData(
            usageCount: $usageCount,
            missingAlt: $media->isImage() && ! $metadata->decorative && blank($metadata->alt),
            missingRights: blank($metadata->credit),
            duplicate: resolve(MediaDuplicateIndex::class)->contains($media),
            unused: $usageCount === 0,
        );
    }

    private function usageCount(Media $media): int
    {
        $attributes = $media->getAttributes();
        $projectedCount = $attributes['tracked_usage_count'] ?? null;

        return is_numeric($projectedCount)
            ? (int) $projectedCount
            : MediaScope::trackedUsageCount($media);
    }
}
