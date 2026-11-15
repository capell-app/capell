<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

/** Where one page stands in one language, as the translation status board shows it. */
enum TranslationBoardStatus: string
{
    case Done = 'done';

    case NeedsUpdate = 'needs_update';

    case Copied = 'copied';

    case Missing = 'missing';

    public function label(): string
    {
        return (string) __('capell-admin::translation_board.status_' . $this->value);
    }

    public function description(): string
    {
        return (string) __('capell-admin::translation_board.status_' . $this->value . '_info');
    }

    public function color(): string
    {
        return match ($this) {
            self::Done => 'success',
            self::NeedsUpdate => 'warning',
            self::Copied => 'gray',
            self::Missing => 'danger',
        };
    }
}
