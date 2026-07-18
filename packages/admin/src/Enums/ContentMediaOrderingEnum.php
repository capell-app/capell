<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Filament\Support\Contracts\HasLabel;

enum ContentMediaOrderingEnum: string implements HasLabel
{
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
