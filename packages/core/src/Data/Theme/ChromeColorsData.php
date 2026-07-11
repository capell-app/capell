<?php

declare(strict_types=1);

namespace Capell\Core\Data\Theme;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class ChromeColorsData extends Data
{
    public function __construct(
        public ?string $backgroundColor = null,
        public ?string $borderColor = null,
        public ?string $color = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->backgroundColor === null
            && $this->borderColor === null
            && $this->color === null;
    }
}
