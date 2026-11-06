<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Http\Middleware\SetSitePermissionScope;
use Capell\Admin\Livewire\GlobalCommandPalette;
use Capell\Admin\Support\PaletteAccess;
use Capell\Admin\Support\PaletteRequestState;
use Capell\Admin\Tests\Fixtures\Models\RealSiteScopedAdminResourceUser;
use Capell\Admin\Tests\Support\SiteScopedUserFactory;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\PermissionTeamContext;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Livewire\EventBus;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    config(['permission.teams' => true, 'auth.providers.users.model' => RealSiteScopedAdminResourceUser::class]);
    $registrar = resolve(PermissionRegistrar::class);
    $registrar->teams = true;
    $registrar->forgetCachedPermissions();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::addPersistentMiddleware([SetSitePermissionScope::class]);

    Route::model('paletteSite', Site::class);
    Route::get('/palette-site/{paletteSite}', static fn (): string => Livewire::mount(GlobalCommandPalette::class))
        ->middleware(['web', SubstituteBindings::class, SetSitePermissionScope::class]);
    Route::model('paletteRecord', Page::class);
    Route::get('/palette-record/{paletteRecord}', static fn (): string => Livewire::mount(GlobalCommandPalette::class))
        ->middleware(['web', SubstituteBindings::class, SetSitePermissionScope::class]);
});

afterEach(function (): void {
    DB::disableQueryLog();
    DB::flushQueryLog();
    auth()->logout();
    $registrar = resolve(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId(null);
    $registrar->teams = false;
    $registrar->forgetCachedPermissions();
    config(['permission.teams' => false]);
});

function paletteSnapshot(string $html): string
{
    preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);

    return html_entity_decode($matches[1] ?? throw new RuntimeException('Missing palette snapshot.'), ENT_QUOTES);
}

function palettePermissionQueryCount(): int
{
    return collect(DB::getQueryLog())->filter(static fn (array $query): bool => preg_match(
        '/\b(sites|roles|permissions|model_has_roles|model_has_permissions|role_has_permissions)\b/',
        $query['query'],
    ) === 1)->count();
}

it('keeps the route bound site through opening and searching on real Livewire updates', function (string $recordType): void {
    $selectedSite = Site::factory()->createOne();
    $recordSite = Site::factory()->createOne();
    $user = SiteScopedUserFactory::create($recordSite, ['View:SettingsPage']);
    PermissionTeamContext::run($selectedSite->getKey(), function () use ($user, $selectedSite): void {
        Role::findOrCreate('selected-site-member', 'web');
        $user->assignRoleForSite($selectedSite, 'selected-site-member');
    }, $user);
    $this->actingAs($user)->withSession(['capell.current_site_id' => $selectedSite->getKey()]);
    $url = 'href="' . SettingsPage::getUrl() . '"';
    $path = $recordType === 'site'
        ? '/palette-site/' . $recordSite->getKey()
        : '/palette-record/' . Page::factory()->createOne(['site_id' => $recordSite->getKey()])->getKey();
    $html = (string) $this->get($path)->assertOk()->assertSee($url, false)
        ->assertSessionHas('capell.current_site_id', $selectedSite->getKey())->getContent();
    $snapshot = paletteSnapshot($html);

    foreach (['', 'settings'] as $query) {
        app()->forgetScopedInstances();
        Livewire::flushState();
        $response = $this->postJson(resolve(HandleRequests::class)->getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot,
            'updates' => ['open' => true, 'query' => $query],
            'calls' => [],
        ]]], ['X-Livewire' => 'true'])->assertOk();
        expect($response->json('components.0.effects.html'))->toContain($url);
        $snapshot = $response->json('components.0.snapshot');
        expect(resolve(PermissionRegistrar::class)->getPermissionsTeamId())->toBeNull();
    }
})->with(['page', 'site']);

it('performs one permission computation for three palette consumers per render', function (bool $update): void {
    $site = Site::factory()->createOne();
    $user = SiteScopedUserFactory::create($site, ['View:SettingsPage']);
    $this->actingAs($user)->withSession(['capell.current_site_id' => $site->getKey()]);
    $record = Page::factory()->createOne(['site_id' => $site->getKey()]);
    $snapshot = null;
    if ($update) {
        $html = (string) $this->get('/palette-record/' . $record->getKey())->assertOk()->getContent();
        $snapshot = paletteSnapshot($html);
        app()->forgetScopedInstances();
        Livewire::flushState();
    }

    $counts = [];
    $stop = resolve(EventBus::class)->on('render', function (Component $component) use (&$counts): ?Closure {
        if (! $component instanceof GlobalCommandPalette) {
            return null;
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        resolve(PaletteAccess::class)->coreCommands();
        $once = palettePermissionQueryCount();

        return function () use (&$counts, $once): void {
            // The template consumes commands and shortcuts: three calls including the first above.
            $counts = [$once, palettePermissionQueryCount()];
        };
    });

    try {
        if ($update) {
            $this->postJson(resolve(HandleRequests::class)->getUpdateUri(), ['components' => [[
                'snapshot' => $snapshot, 'updates' => ['query' => 'settings'], 'calls' => [],
            ]]], ['X-Livewire' => 'true'])->assertOk();
        } else {
            $this->get('/palette-record/' . $record->getKey())->assertOk();
        }

        expect($counts)->toHaveCount(2)
            ->and($counts[1])->toBe($counts[0])
            ->and($counts[0])->toBeGreaterThan(0)->toBeLessThanOrEqual(6);
    } finally {
        $stop();
    }
})->with(['full page' => false, 'palette update' => true]);

it('forgets both resolved site and cached access between sequential worker requests', function (): void {
    $allowedSite = Site::factory()->createOne();
    $deniedSite = Site::factory()->createOne();
    $user = SiteScopedUserFactory::create($allowedSite, ['View:SettingsPage']);
    $this->actingAs($user);
    $firstState = resolve(PaletteRequestState::class);
    $url = 'href="' . SettingsPage::getUrl() . '"';
    $this->get('/palette-record/' . Page::factory()->createOne(['site_id' => $allowedSite->getKey()])->getKey())->assertOk()->assertSee($url, false);

    app()->forgetScopedInstances();
    Livewire::flushState();
    expect(resolve(PaletteRequestState::class))->not->toBe($firstState);
    $this->get('/palette-record/' . Page::factory()->createOne(['site_id' => $deniedSite->getKey()])->getKey())->assertOk()->assertDontSee($url, false);
});

it('resolves and computes access once when no site middleware has run', function (): void {
    $site = Site::factory()->createOne();
    $user = SiteScopedUserFactory::create($site, ['View:SettingsPage']);
    $this->actingAs($user);
    request()->setUserResolver(fn (): RealSiteScopedAdminResourceUser => $user);
    DB::enableQueryLog();
    $first = resolve(PaletteAccess::class)->coreCommands();
    $once = palettePermissionQueryCount();
    expect($first)->toHaveKey('capell.settings')->and($once)->toBeGreaterThan(0);

    expect(resolve(PaletteAccess::class)->coreCommands())->toBe($first)
        ->and(resolve(PaletteAccess::class)->coreCommands())->toBe($first)
        ->and(palettePermissionQueryCount())->toBe($once)
        ->and(resolve(PermissionRegistrar::class)->getPermissionsTeamId())->toBeNull();
});

it('isolates cached commands by actor panel and resolved team within a request scope', function (): void {
    $allowedSite = Site::factory()->createOne();
    $deniedSite = Site::factory()->createOne();
    $allowedRecord = Page::factory()->createOne(['site_id' => $allowedSite->getKey()]);
    $deniedRecord = Page::factory()->createOne(['site_id' => $deniedSite->getKey()]);
    $user = SiteScopedUserFactory::create($allowedSite, ['View:SettingsPage']);
    $deniedUser = SiteScopedUserFactory::create($allowedSite);
    $url = 'href="' . SettingsPage::getUrl() . '"';

    $this->actingAs($user);
    $this->get('/palette-record/' . $allowedRecord->getKey())->assertOk()->assertSee($url, false);
    $this->get('/palette-record/' . $deniedRecord->getKey())->assertOk()->assertDontSee($url, false);
    $this->get('/palette-record/' . $allowedRecord->getKey())->assertOk()->assertSee($url, false);

    $this->actingAs($deniedUser);
    $this->get('/palette-record/' . $allowedRecord->getKey())->assertOk()->assertDontSee($url, false);

    $this->actingAs($user);
    Filament::setCurrentPanel(Panel::make()->id('empty')->authGuard('web'));
    expect(resolve(PaletteAccess::class)->coreCommands())->toBe([]);
});

it('drops revoked commands and shortcuts within the same request', function (string $reset): void {
    $site = Site::factory()->createOne();
    $user = SiteScopedUserFactory::create($site, ['View:SettingsPage']);
    $this->actingAs($user);
    request()->setUserResolver(fn (): RealSiteScopedAdminResourceUser => $user);
    $registrar = resolve(PermissionRegistrar::class);
    // An active middleware scope also leaves loaded relations available to consumers.
    $registrar->setPermissionsTeamId($site->getKey());

    $state = resolve(PaletteRequestState::class);
    $url = SettingsPage::getUrl();
    $label = (string) __('capell-admin::palette.settings');
    $render = fn (): string => Livewire::test(GlobalCommandPalette::class)->html();
    expect($render())->toContain($url)->toContain($label);
    expect(view('capell-admin::components.keyboard-shortcuts')->render())->toContain('settings');

    DB::table('model_has_permissions')->where('model_id', $user->getKey())
        ->where('model_type', $user->getMorphClass())->where('team_id', $site->getKey())->delete();
    match ($reset) {
        'registrar' => $registrar->forgetCachedPermissions(),
        'roles' => $user->unsetRelation('roles'),
        'permissions' => $user->unsetRelation('permissions'),
        'all relations' => $user->unsetRelations(),
        default => throw new InvalidArgumentException('Unknown permission reset: ' . $reset),
    };
    // Match the permission-reset contract after a registrar reset too.
    if ($reset === 'registrar') {
        $user->unsetRelation('roles')->unsetRelation('permissions');
    }

    expect(resolve(PaletteRequestState::class))->toBe($state);
    expect($render())->not->toContain($url)->not->toContain($label);
    expect(view('capell-admin::components.keyboard-shortcuts')->render())->not->toContain('settings');
})->with(['registrar', 'roles', 'permissions', 'all relations']);

it('invalidates commands when only the registrar cache is forgotten', function (): void {
    $site = Site::factory()->createOne();
    $user = SiteScopedUserFactory::create($site, ['View:SettingsPage']);
    $this->actingAs($user);
    request()->setUserResolver(fn (): RealSiteScopedAdminResourceUser => $user);
    $access = resolve(PaletteAccess::class);
    $state = resolve(PaletteRequestState::class);
    expect($access->coreCommands())->toHaveKey('capell.settings');
    DB::table('model_has_permissions')->where('model_id', $user->getKey())
        ->where('model_type', $user->getMorphClass())->where('team_id', $site->getKey())->delete();
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(resolve(PaletteRequestState::class))->toBe($state);
    expect($access->coreCommands())->not->toHaveKey('capell.settings');
});

it('invalidates commands on relation resets after fallback team resolution', function (string $reset): void {
    $site = Site::factory()->createOne();
    $user = SiteScopedUserFactory::create($site, ['View:SettingsPage']);
    $this->actingAs($user);
    request()->setUserResolver(fn (): RealSiteScopedAdminResourceUser => $user);
    $access = resolve(PaletteAccess::class);
    expect($access->coreCommands())->toHaveKey('capell.settings');
    DB::table('model_has_permissions')->where('model_id', $user->getKey())
        ->where('model_type', $user->getMorphClass())->where('team_id', $site->getKey())->delete();
    if ($reset === 'all relations') {
        $user->unsetRelations();
    } else {
        $user->unsetRelation($reset);
    }

    expect($access->coreCommands())->not->toHaveKey('capell.settings');
})->with(['roles', 'permissions', 'all relations']);

it('resolves the switched actors own team without another middleware pass', function (): void {
    $firstSite = Site::factory()->createOne();
    $secondSite = Site::factory()->createOne();
    $first = SiteScopedUserFactory::create($firstSite, ['View:SettingsPage']);
    $second = SiteScopedUserFactory::create($secondSite, ['View:SettingsPage']);
    $state = resolve(PaletteRequestState::class);
    $access = resolve(PaletteAccess::class);
    $this->actingAs($first);
    request()->setUserResolver(fn (): RealSiteScopedAdminResourceUser => $first);
    expect($access->coreCommands())->toHaveKey('capell.settings');

    $this->actingAs($second);
    request()->setUserResolver(fn (): RealSiteScopedAdminResourceUser => $second);
    expect(resolve(PaletteRequestState::class))->toBe($state);
    expect($access->coreCommands())->toHaveKey('capell.settings');
});

it('drops commands after a role is revoked without flushing the registrar', function (): void {
    $site = Site::factory()->createOne();
    $user = SiteScopedUserFactory::create($site);
    PermissionTeamContext::run($site->getKey(), function (): void {
        $role = Role::findByName('test-site-member', 'web');
        $role->givePermissionTo(Permission::findOrCreate('View:SettingsPage', 'web'));
    }, $user);
    $this->actingAs($user);
    request()->setUserResolver(fn (): RealSiteScopedAdminResourceUser => $user);
    $access = resolve(PaletteAccess::class);
    expect($access->coreCommands())->toHaveKey('capell.settings');
    $user->removeRoleForSite($site, 'test-site-member');
    expect($access->coreCommands())->not->toHaveKey('capell.settings');
    expect(array_column($access->shortcuts(), 'target'))->not->toContain('settings');
});
