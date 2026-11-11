<?php

declare(strict_types=1);

namespace Capell\Core\Observers;

use Capell\Core\Actions\Properties\ReleasePropertyMediaReferencesAction;
use Capell\Core\Models\Media;

/** A trashed media item stays referenced so it can be restored; only a permanent delete releases its property values. */
final class MediaPropertyReferenceObserver
{
    public function forceDeleting(Media $media): void
    {
        ReleasePropertyMediaReferencesAction::run($media);
    }
}
