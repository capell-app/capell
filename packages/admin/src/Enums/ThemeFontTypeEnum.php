<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum ThemeFontTypeEnum: string implements HasLabel
{
    use HasEnumOptions;

    case Url = 'url';
    case Local = 'local';

    public function getLabel(): string
    {
        return match ($this) {
            self::Url => (string) __('capell-admin::form.font_type_url'),
            self::Local => (string) __('capell-admin::form.font_type_local'),
        };
    }
}
