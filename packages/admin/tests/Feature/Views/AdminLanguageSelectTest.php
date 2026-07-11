<?php

declare(strict_types=1);

use Capell\Core\Database\Factories\UserFactory;
use Capell\Core\Models\Language;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('renders the admin language select in the user menu', function (): void {
    /** @var view-string $view */
    $view = 'capell-admin::components.user-menu.admin-language-select';
    $language = Language::factory()->english()->create(['status' => true]);
    $user = UserFactory::new()->createOne(['preferred_admin_language_id' => $language->getKey()]);

    actingAs($user);

    $html = view($view)->render();

    expect($html)
        ->toContain('capell-admin-user-menu-language')
        ->toContain('data-capell-admin-language-select-panel="true"')
        ->toContain('fi-dropdown-list-item')
        ->toContain('dark:bg-gray-950')
        ->toContain(route('capell-admin.profile.language.update'))
        ->toContain('English')
        ->toContain('selected');
});

it('does not render the admin language select when there are no enabled languages', function (): void {
    /** @var view-string $view */
    $view = 'capell-admin::components.user-menu.admin-language-select';
    Language::factory()->english()->create(['status' => false]);
    actingAs(UserFactory::new()->createOne());

    $html = view($view)->render();

    expect($html)->not->toContain('capell-admin-user-menu-language');
});

it('does not render the admin language select on Filament auth routes', function (): void {
    /** @var view-string $view */
    $view = 'capell-admin::components.user-menu.admin-language-select';
    Language::factory()->english()->create(['status' => true]);
    actingAs(UserFactory::new()->createOne());

    Route::get('/admin/multi-factor-authentication/set-up', fn (): Factory|View => view($view))
        ->name('filament.admin.auth.multi-factor-authentication.set-up-required');

    get(route('filament.admin.auth.multi-factor-authentication.set-up-required'))
        ->assertOk()
        ->assertDontSee('capell-admin-user-menu-language');
});

it('does not render the admin language select before the user preference column exists', function (): void {
    /** @var view-string $view */
    $view = 'capell-admin::components.user-menu.admin-language-select';
    Language::factory()->english()->create(['status' => true]);
    actingAs(UserFactory::new()->createOne());

    Schema::shouldReceive('hasTable')
        ->with('users')
        ->andReturnTrue();
    Schema::shouldReceive('hasColumn')
        ->with('users', 'preferred_admin_language_id')
        ->andReturnFalse();

    $html = view($view)->render();

    expect($html)->not->toContain('capell-admin-user-menu-language');
});
