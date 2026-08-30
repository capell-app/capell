<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use Capell\Admin\Contracts\Extensions\ExtensionTableDataSource;
use Capell\Admin\Enums\AdminZone;
use Capell\Admin\Filament\Pages\Extensions\Tables\ExtensionsTable;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Filament\Resources\Pages\Tables\PagesTable;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class AdminZoneContextData
{
    public function __construct(
        public AdminZone $zone,
        public string $surface,
        public ?Authenticatable $user = null,
        public mixed $record = null,
        public mixed $subject = null,
    ) {}

    public static function pageListTable(?Authenticatable $user = null): self
    {
        return new self(
            zone: AdminZone::PageListTableColumns,
            surface: PagesTable::class,
            user: $user,
        );
    }

    public static function pageEdit(EditPage $page, AdminZone $zone = AdminZone::PageEditFormActions, ?Authenticatable $user = null): self
    {
        return new self(
            zone: $zone,
            surface: EditPage::class,
            user: $user,
            record: $page->getRecord(),
            subject: $page,
        );
    }

    public static function extensionsDashboard(ExtensionsPage $page, AdminZone $zone): self
    {
        return new self(
            zone: $zone,
            surface: ExtensionsPage::class,
            user: auth()->user(),
            subject: $page,
        );
    }

    public static function extensionsTable(ExtensionTableDataSource $source, AdminZone $zone): self
    {
        return new self(
            zone: $zone,
            surface: ExtensionsTable::class,
            user: auth()->user(),
            subject: $source,
        );
    }
}
