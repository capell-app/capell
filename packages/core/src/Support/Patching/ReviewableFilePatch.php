<?php

declare(strict_types=1);

namespace Capell\Core\Support\Patching;

/** Optional capability for patches whose file writes can be bound to a review. */
interface ReviewableFilePatch extends ReviewablePatch
{
    public function applyReviewed(FilePatchReview $review): void;
}
