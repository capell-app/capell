<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Capell\Core\Enums\Extensions\ExtensionSurfaceStability;

enum AdminZone: string
{
    case PageEditFormActions = 'admin.pages.edit.form.actions';
    case PageEditContentBefore = 'admin.pages.edit.content.before';
    case PageEditContentAfter = 'admin.pages.edit.content.after';
    case PageEditHeaderWidgets = 'admin.pages.edit.header.widgets';
    case PageListTableColumns = 'admin.pages.list.table.columns';
    case ExtensionsDashboardContentBefore = 'admin.extensions.dashboard.content.before';
    case ExtensionsDashboardContentAfter = 'admin.extensions.dashboard.content.after';
    case ExtensionsDashboardHeaderActions = 'admin.extensions.dashboard.header.actions';
    case ExtensionsDashboardHeaderWidgets = 'admin.extensions.dashboard.header.widgets';
    case ExtensionsDashboardTableFilters = 'admin.extensions.dashboard.table.filters';
    case ExtensionsDashboardTableColumns = 'admin.extensions.dashboard.table.columns';
    case ExtensionsDashboardTableRecordActions = 'admin.extensions.dashboard.table.record-actions';

    public function stability(): ExtensionSurfaceStability
    {
        return ExtensionSurfaceStability::Stable;
    }

    public function summary(): string
    {
        return match ($this) {
            self::PageEditFormActions => 'Actions contributed to the Page edit form.',
            self::PageEditContentBefore => 'Content rendered before the Page edit form.',
            self::PageEditContentAfter => 'Content rendered after the Page edit form.',
            self::PageEditHeaderWidgets => 'Widgets contributed to the Page edit header.',
            self::PageListTableColumns => 'Columns contributed to the Capell Page list table.',
            self::ExtensionsDashboardContentBefore => 'Content rendered before the Extensions dashboard table.',
            self::ExtensionsDashboardContentAfter => 'Content rendered after the Extensions dashboard table.',
            self::ExtensionsDashboardHeaderActions => 'Actions contributed to the Extensions dashboard header.',
            self::ExtensionsDashboardHeaderWidgets => 'Widgets contributed to the Extensions dashboard header.',
            self::ExtensionsDashboardTableFilters => 'Filters contributed to the Extensions dashboard table.',
            self::ExtensionsDashboardTableColumns => 'Columns contributed to the Extensions dashboard table.',
            self::ExtensionsDashboardTableRecordActions => 'Actions contributed to Extensions dashboard table records.',
        };
    }
}
