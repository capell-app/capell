<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Filament\Support\Icons\Heroicon;

enum AdminWorkspaceEnum: string
{
    case Editor = 'editor';
    case Marketer = 'marketer';
    case Operator = 'operator';
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::Editor => (string) __('capell-admin::workspace.switcher_tools.editor'),
            self::Marketer => (string) __('capell-admin::workspace.switcher_tools.marketer'),
            self::Operator => (string) __('capell-admin::workspace.switcher_tools.operator'),
            self::All => (string) __('capell-admin::workspace.switcher_tools.all'),
        };
    }

    /** Says what the person gets by choosing this group, so a first-time user can pick without guessing. */
    public function description(): string
    {
        return match ($this) {
            self::Editor => (string) __('capell-admin::workspace.switcher_tools.editor_description'),
            self::Marketer => (string) __('capell-admin::workspace.switcher_tools.marketer_description'),
            self::Operator => (string) __('capell-admin::workspace.switcher_tools.operator_description'),
            self::All => (string) __('capell-admin::workspace.switcher_tools.all_description'),
        };
    }

    public function icon(): Heroicon
    {
        return match ($this) {
            self::Editor => Heroicon::OutlinedPencilSquare,
            self::Marketer => Heroicon::OutlinedMegaphone,
            self::Operator => Heroicon::OutlinedWrenchScrewdriver,
            self::All => Heroicon::OutlinedSquares2x2,
        };
    }
}
