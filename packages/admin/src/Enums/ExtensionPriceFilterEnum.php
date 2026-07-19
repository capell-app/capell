<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum ExtensionPriceFilterEnum: string implements HasLabel
{
    use HasEnumOptions;

    case Free = 'free';
    case Paid = 'paid';

    public function getLabel(): string
    {
        return (string) __('capell-admin::filter.' . $this->value);
    }
}
