<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum InteractionBehavior: string implements HasLabel
{
    use HasEnumOptions;

    case Modal = 'modal';
    case SlideOver = 'slide_over';
    case InlineReveal = 'inline_reveal';
    case ReplaceRegion = 'replace_region';

    public function getLabel(): string
    {
        return (string) __('capell::generic.' . $this->value);
    }
}
