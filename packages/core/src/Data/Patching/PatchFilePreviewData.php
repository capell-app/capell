<?php

declare(strict_types=1);

namespace Capell\Core\Data\Patching;

use Spatie\LaravelData\Data;

final class PatchFilePreviewData extends Data
{
    public function __construct(
        public string $path,
        public string $before,
        public string $after,
        public bool $truncated = false,
        public ?string $sourceFingerprint = null,
    ) {}
}
