<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Media;

use Capell\Core\Models\Media;
use Capell\Core\Support\Permissions\SiteAccess;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Counts the tracked, site-accessible attachments that the default usage list omits.
 */
final class CountOmittedMediaUsageAction
{
    use AsFake;
    use AsObject;

    public function handle(?Media $media): int
    {
        if (! $media instanceof Media) {
            return 0;
        }

        $total = SiteAccess::current()->scopeAssetAttachments($media->assetRelations()->getQuery())->count();

        return max(0, $total - BuildMediaUsageItemsAction::DEFAULT_LIMIT);
    }
}
