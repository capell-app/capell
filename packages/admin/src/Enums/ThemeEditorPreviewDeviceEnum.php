<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum ThemeEditorPreviewDeviceEnum: string implements HasLabel
{
    use HasEnumOptions;

    case Desktop = 'desktop';
    case Tablet = 'tablet';
    case Mobile = 'mobile';

    public function getLabel(): string
    {
        return match ($this) {
            self::Desktop => (string) __('capell-admin::theme-editor.options.desktop'),
            self::Tablet => (string) __('capell-admin::theme-editor.options.tablet'),
            self::Mobile => (string) __('capell-admin::theme-editor.options.mobile'),
        };
    }
}
