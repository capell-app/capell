<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum PageRequiredFieldEnum: string implements HasLabel
{
    use HasEnumOptions;

    case Title = 'title';
    case Content = 'content';

    public function getLabel(): string
    {
        return match ($this) {
            self::Title => (string) __('capell-admin::form.title'),
            self::Content => (string) __('capell-admin::form.content'),
        };
    }
}
