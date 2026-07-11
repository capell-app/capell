<?php

declare(strict_types=1);

use Capell\Admin\Actions\AssignPermissionsToRole;
use Capell\Admin\Enums\PageEnum;
use Capell\Marketplace\Enums\MarketplacePermission;
use Capell\Marketplace\Filament\Pages\MarketplacePage;
use Spatie\Permission\Models\Permission;

it('keeps manifest permissions traceable to the enum', function (): void {
    $manifestContents = file_get_contents(dirname(__DIR__, 2) . '/capell.json');

    $manifest = json_decode(
        $manifestContents === false ? '{}' : $manifestContents,
        true,
    );

    expect($manifest['permissions'] ?? [])->toEqualCanonicalizing(MarketplacePermission::names());
});

it('inserts marketplace page permissions through admin page permission generation', function (): void {
    AssignPermissionsToRole::run(
        pages: [
            PageEnum::Extension,
            MarketplacePage::class,
        ],
    );

    $installed = Permission::query()
        ->whereIn('name', MarketplacePermission::names())
        ->pluck('name')
        ->all();

    expect($installed)->toEqualCanonicalizing(MarketplacePermission::names());
});
