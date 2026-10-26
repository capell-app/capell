<?php

declare(strict_types=1);

namespace Capell\Core\Data\Patching;

use Spatie\LaravelData\Data;

final class PatchPreviewData extends Data
{
    /** @param list<PatchFilePreviewData> $files */
    public function __construct(
        public array $files,
        public string $fingerprint,
        public bool $backsUp = true,
    ) {}
}
