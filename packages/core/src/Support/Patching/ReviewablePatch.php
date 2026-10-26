<?php

declare(strict_types=1);

namespace Capell\Core\Support\Patching;

use Capell\Core\Data\Patching\PatchPreviewData;
use Capell\Core\Data\Patching\PatchRecoveryData;

/** Optional capability; existing Patch implementations remain compatible. */
interface ReviewablePatch extends Patch
{
    public function preview(): PatchPreviewData;

    /** Actual recovery references, including partial or failed application. */
    public function recoveryResult(): PatchRecoveryData;
}
