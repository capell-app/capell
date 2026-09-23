<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use Spatie\LaravelData\Data;

final class MediaReplacementFileData extends Data
{
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly ?string $stagedPath,
        public readonly ?string $backupPath,
        public bool $changed = false,
    ) {}
}
