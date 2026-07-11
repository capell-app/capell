<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Themes;

use Spatie\LaravelData\Data;

final class ThemeEditorOptionData extends Data
{
    public function __construct(
        public string $value,
        public string $label,
    ) {}
}
