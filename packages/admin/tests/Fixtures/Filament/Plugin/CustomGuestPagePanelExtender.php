<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Capell\Admin\Support\AdminRouteProvenance;
use Filament\Panel;
use Illuminate\Support\Facades\Route;
use Override;

final class CustomGuestPagePanelExtender implements AdminPanelExtender
{
    #[Override]
    public function extend(Panel $panel): void
    {
        $panel->routes(static function (Panel $panel): void {
            Route::get('custom-guest-default', CustomGuestPage::class)->name('custom-guest-default');
            Route::livewire('custom-guest-livewire', CustomGuestPage::class)->name('custom-guest-livewire');
            AdminRouteProvenance::panel($panel->getId(), static function (): void {
                Route::get('custom-guest-declared', CustomGuestPage::class)->name('custom-guest-declared');
            }, guests: true);
        });
        $panel->authenticatedRoutes(static function (): void {
            Route::get('custom-guest-authenticated', CustomGuestPage::class)->name('custom-guest-authenticated');
        });
    }
}
