<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Filament\Support\Contracts\HasLabel;

enum EditorEnum: string implements HasLabel
{
    case RichEditor = 'RichEditor';
    case TinyMCE = 'TinyMCE';

    public function getLabel(): string
    {
        return match ($this) {
            self::RichEditor => __('Tiptap Editor'),
            self::TinyMCE => __('TinyMCE Editor'),
        };
    }
}
