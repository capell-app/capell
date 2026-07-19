<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum ExtensionHealthFilterEnum: string implements HasLabel
{
    use HasEnumOptions;

    case Ok = 'ok';
    case Warning = 'warning';
    case Critical = 'critical';

    public function getLabel(): string
    {
        return (string) __('capell-admin::filter.health_' . $this->value);
    }
}
