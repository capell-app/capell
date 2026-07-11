<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\CapellDashboard;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\get;

it('redirects logged in admin panel users to the installer before Capell is installed', function (): void {
    test()->actingAsAdmin();

    Schema::drop('sites');

    Route::get('/install', fn (): string => 'Installer')->name('capell-installer.show');

    get(CapellDashboard::getUrl())->assertRedirect(route('capell-installer.show'));
});
