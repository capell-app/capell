<?php

declare(strict_types=1);

namespace Capell\Core\Data\Theme;

use Capell\Core\Enums\FontTypeEnum;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class FontData extends Data
{
    /**
     * @param  array<int, string>|null  $files
     */
    public function __construct(
        public FontTypeEnum $type,
        public string $name,
        public ?string $weight = null,
        public ?string $style = null,
        public ?string $url = null,
        public ?array $files = null,
    ) {}
}
