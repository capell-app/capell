<?php

declare(strict_types=1);

namespace Capell\Core\Observers;

use Capell\Core\Actions\Properties\ReleasePropertyMediaReferencesAction;
use Capell\Core\Models\Media;

/**
 * A trashed media item stays referenced so it can be restored; only a permanent delete releases its
 * property values, and only after the row is gone, so a delete that fails leaves them in place.
 */
final class MediaPropertyReferenceObserver
{
    public function deleted(Media $media): void
    {
        if ($media->isForceDeleting()) {
            ReleasePropertyMediaReferencesAction::run((int) $media->getKey());
        }
    }
}
