<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum InteractionModalSizeEnum: string implements HasLabel
{
    use HasEnumOptions;

    case Small = 'sm';
    case Medium = 'md';
    case Large = 'lg';
    case ExtraLarge = 'xl';
    case FullScreen = 'screen';

    public function getLabel(): string
    {
        return match ($this) {
            self::Small => (string) __('capell-admin::generic.small'),
            self::Medium => (string) __('capell-admin::generic.medium'),
            self::Large => (string) __('capell-admin::generic.large'),
            self::ExtraLarge => (string) __('capell-admin::generic.extra_large'),
            self::FullScreen => (string) __('capell-admin::generic.full_screen'),
        };
    }
}
