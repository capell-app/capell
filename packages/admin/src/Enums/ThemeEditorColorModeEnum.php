<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum ThemeEditorColorModeEnum: string implements HasLabel
{
    use HasEnumOptions;

    case Light = 'light';
    case Dark = 'dark';

    public function getLabel(): string
    {
        return match ($this) {
            self::Light => (string) __('capell-admin::theme-editor.options.light'),
            self::Dark => (string) __('capell-admin::theme-editor.options.dark'),
        };
    }
}
