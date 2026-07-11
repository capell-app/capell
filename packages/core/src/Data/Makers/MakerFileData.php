<?php

declare(strict_types=1);

namespace Capell\Core\Data\Makers;

use Spatie\LaravelData\Data;

class MakerFileData extends Data
{
    public function __construct(
        public string $path,
        public string $operation,
        public bool $exists,
        public bool $writable,
        public ?string $contents = null,
    ) {}
}
