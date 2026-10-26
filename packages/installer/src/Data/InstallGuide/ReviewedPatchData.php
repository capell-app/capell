<?php

declare(strict_types=1);

namespace Capell\Installer\Data\InstallGuide;

use Capell\Core\Data\Patching\PatchPreviewData;
use Capell\Core\Support\Patching\PatchStatus;
use Spatie\LaravelData\Data;

final class ReviewedPatchData extends Data
{
    public function __construct(
        public string $patchId,
        public string $label,
        public PatchStatus $status,
        public ?PatchPreviewData $preview,
        public string $guidance,
    ) {}
}
