<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum HeaderShadowEnum: string implements HasLabel
{
    use HasEnumOptions;

    case None = 'none';
    case Subtle = 'subtle';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => (string) __('capell-admin::generic.none'),
            self::Subtle => (string) __('capell-admin::form.shadow_subtle'),
        };
    }
}
