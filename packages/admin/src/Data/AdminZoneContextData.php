<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use Capell\Admin\Enums\AdminZone;
use Capell\Admin\Filament\Resources\Pages\Tables\PagesTable;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class AdminZoneContextData
{
    public function __construct(
        public AdminZone $zone,
        public string $surface,
        public ?Authenticatable $user = null,
        public mixed $record = null,
    ) {}

    public static function pageListTable(?Authenticatable $user = null): self
    {
        return new self(
            zone: AdminZone::PageListTableColumns,
            surface: PagesTable::class,
            user: $user,
        );
    }
}
