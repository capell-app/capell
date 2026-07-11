<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Themes;

use Spatie\LaravelData\Data;

final class ThemeEditorPreviewData extends Data
{
    /**
     * @param  array<string, string>  $cssVariables
     * @param  array<string, string>  $dataAttributes
     */
    public function __construct(
        public string $html,
        public array $cssVariables,
        public array $dataAttributes,
    ) {}
}
