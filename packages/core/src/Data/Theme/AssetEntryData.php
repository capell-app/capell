<?php

declare(strict_types=1);

namespace Capell\Core\Data\Theme;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class AssetEntryData extends Data
{
    public function __construct(
        public string $name,
        public ?string $path = null,
        public ?string $type = null,
    ) {}
}
