<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum ContentMediaOrderingEnum: string implements HasLabel
{
    use HasEnumOptions;

    case Before = 'before';
    case After = 'after';

    public function getLabel(): string
    {
        return match ($this) {
            self::Before => (string) __('capell-admin::widget.content_media_before'),
            self::After => (string) __('capell-admin::widget.content_media_after'),
        };
    }
}
