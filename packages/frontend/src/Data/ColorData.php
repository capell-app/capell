<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Actions\ColorConverterAction;
use Spatie\LaravelData\Data;

class ColorData extends Data
{
    public function __construct(
        public string $name,
        public mixed $color,
    ) {}

    public function getColor(): ?string
    {
        return ColorConverterAction::run($this->color);
    }
}
