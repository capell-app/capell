<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Capell\Core\Enums\Extensions\ExtensionSurfaceStability;

enum AdminZone: string
{
    case PageListTableColumns = 'admin.pages.list.table.columns';

    public function stability(): ExtensionSurfaceStability
    {
        return ExtensionSurfaceStability::Stable;
    }

    public function summary(): string
    {
        return match ($this) {
            self::PageListTableColumns => 'Columns contributed to the Capell Page list table.',
        };
    }
}
