<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum PresentationLazyPolicy: string implements HasLabel
{
    use HasEnumOptions;

    case ServerRendered = 'server-rendered';
    case Visible = 'visible';
    case Interaction = 'interaction';
    case Idle = 'idle';

    public function getLabel(): string
    {
        return match ($this) {
            self::ServerRendered => (string) __('capell::generic.server_rendered'),
            self::Visible => (string) __('capell::generic.visible'),
            self::Interaction => (string) __('capell::generic.interaction'),
            self::Idle => (string) __('capell::generic.idle'),
        };
    }
}
