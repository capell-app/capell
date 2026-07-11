<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Themes;

use Spatie\LaravelData\Data;

final class ThemeEditorGroupData extends Data
{
    /** @param array<int, ThemeEditorTokenData> $tokens */
    public function __construct(
        public string $key,
        public string $label,
        public array $tokens,
    ) {}
}
