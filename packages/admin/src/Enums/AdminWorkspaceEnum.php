<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

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
}
