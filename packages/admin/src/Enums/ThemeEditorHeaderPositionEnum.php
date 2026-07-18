<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum ThemeEditorHeaderPositionEnum: string implements HasLabel
{
    use HasEnumOptions;

    case Static_ = 'static';
    case Sticky = 'sticky';
    case Fixed = 'fixed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Static_ => (string) __('capell-admin::theme-editor.options.static'),
            self::Sticky => (string) __('capell-admin::theme-editor.options.sticky'),
            self::Fixed => (string) __('capell-admin::theme-editor.options.fixed'),
        };
    }
}
