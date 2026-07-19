<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum PresentationLoadingStrategy: string implements HasLabel
{
    use HasEnumOptions;

    case Eager = 'eager';
    case Visible = 'visible';
    case Interaction = 'interaction';
    case Idle = 'idle';

    public function getLabel(): string
    {
        return (string) __('capell::generic.' . $this->value);
    }
}
