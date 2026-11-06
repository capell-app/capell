<?php

declare(strict_types=1);

use Capell\Admin\Actions\ResolveAdminHelpAction;
use Capell\Admin\Data\AdminHelpEntryData;
use Capell\Admin\Support\AdminHelpRegistry;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Route;

uses(CreatesAdminUser::class)->group('admin');

it('provides help for the built-in screens', function (): void {
    $routes = [
        'filament.admin.resources.pages.index', 'filament.admin.resources.pages.edit',
        'filament.admin.resources.media.index', 'filament.admin.resources.sites.index',
        'filament.admin.resources.layouts.index', 'filament.admin.resources.themes.index',
        'filament.admin.resources.blueprints.index', 'filament.admin.resources.users.index',
        'filament.admin.resources.shield.roles.index', 'filament.admin.pages.settings',
        'filament.admin.pages.extensions', 'filament.admin.resources.redirects.index',
        'filament.admin.pages.sitemap', 'filament.admin.pages.site-health',
        'filament.admin.resources.languages.index', 'filament.admin.resources.activities.index',
        'filament.admin.pages.recently-deleted', 'filament.admin.pages.analytics-dashboard',
        'filament.admin.pages.upgrade', 'filament.admin.pages.capell-dashboard',
    ];

    foreach ($routes as $route) {
        if ($route !== 'filament.admin.pages.recently-deleted') {
            expect(Route::has($route))->toBeTrue('Missing route: ' . $route);
        }

        $entry = resolve(AdminHelpRegistry::class)->get($route);
        expect($entry)->toBeInstanceOf(AdminHelpEntryData::class);
        assert($entry instanceof AdminHelpEntryData);
        expect($entry->title)->not->toBeEmpty()
            ->and($entry->summary)->not->toBeEmpty()
            ->and($entry->nextAction)->not->toBeEmpty();
    }

    expect(resolve(AdminHelpRegistry::class)->get('unknown.route'))->toBeNull();
});

it('shows accessible help and glossary on the dashboard', function (): void {
    test()->actingAsAdmin();
    $response = $this->get('/admin');
    $response->assertOk()->assertElementExists('details summary[aria-label]');
    $glossary = require __DIR__ . '/../../../resources/lang/en/glossary.php';
    foreach (['blueprint', 'schema', 'interceptor', 'page_type', 'workspace', 'lockdown', 'fragment', 'hydration', 'canonical', 'slug', 'locale'] as $term) {
        expect($glossary)->toHaveKey($term)
            ->and($glossary[$term])->toBeString()->not->toBeEmpty();
        $response->assertSee($glossary[$term]);
    }

    expect(ResolveAdminHelpAction::run())->not->toBeNull();
});
