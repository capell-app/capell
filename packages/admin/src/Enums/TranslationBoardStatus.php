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
        return (string) match ($this) {
            self::Done => __('capell-admin::translation_board.status_done'),
            self::NeedsUpdate => __('capell-admin::translation_board.status_needs_update'),
            self::Copied => __('capell-admin::translation_board.status_copied'),
            self::Missing => __('capell-admin::translation_board.status_missing'),
        };
    }

    public function description(): string
    {
        return (string) match ($this) {
            self::Done => __('capell-admin::translation_board.status_done_info'),
            self::NeedsUpdate => __('capell-admin::translation_board.status_needs_update_info'),
            self::Copied => __('capell-admin::translation_board.status_copied_info'),
            self::Missing => __('capell-admin::translation_board.status_missing_info'),
        };
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
