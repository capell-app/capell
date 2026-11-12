<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\IssuePagePreviewTokenAction;
use Capell\Admin\Actions\Themes\CreateThemePreviewUrlAction;
use Capell\Admin\Filament\Resources\Pages\Actions\PreviewDraftPageHeaderAction;
use Capell\Admin\Tests\Support\Models\TeamScopedPanelUser;
use Capell\Core\Contracts\Themes\ThemePreviewRendererInterface;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Security\LockdownStore;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

uses(CreatesAdminUser::class)
    ->group('preview');

afterEach(function (): void {
    setPermissionsTeamId(null);
    resolve(PermissionRegistrar::class)->teams = false;
    config(['permission.teams' => false]);
});

it('rejects unsigned page preview requests', function (): void {
    $page = Page::factory()->createOne();

    test()->actingAsAdmin()
        ->get(route('capell.admin.preview-page', ['page' => $page]))
        ->assertForbidden();
});

it('renders signed page previews through the frontend preview renderer', function (): void {
    $theme = Theme::factory()->defaultMeta()->createOne(['key' => 'preview-route-theme']);
    $site = Site::factory()
        ->theme($theme)
        ->withTranslations()
        ->createOne(['name' => 'Preview Site']);
    $layout = Layout::factory()->site($site)->createOne();
    $page = Page::factory()
        ->site($site)
        ->layout($layout)
        ->withTranslations(languages: $site->language, data: ['content' => '<p>Draft preview body</p>'])
        ->createOne(['name' => 'Preview Draft']);

    $renderer = new class implements ThemePreviewRendererInterface
    {
        public ?Theme $theme = null;

        public ?Site $site = null;

        public ?Page $page = null;

        #[Override]
        public function render(
            Theme $theme,
            Site $site,
            Page $page,
            ?Language $language = null,
            ?SiteDomain $siteDomain = null,
        ): Response {
            $this->theme = $theme;
            $this->site = $site;
            $this->page = $page;

            return new Response('frontend preview response');
        }
    };

    app()->instance(ThemePreviewRendererInterface::class, $renderer);

    $response = test()->actingAsAdmin()
        ->get(IssuePagePreviewTokenAction::run($page));

    $response
        ->assertOk()
        ->assertSee('frontend preview response');

    expect((string) $response->headers->get('Cache-Control'))
        ->toContain('no-store')
        ->toContain('private')
        ->and($renderer->theme?->is($theme))->toBeTrue()
        ->and($renderer->site?->is($site))->toBeTrue()
        ->and($renderer->page?->is($page))->toBeTrue();
});

it('binds the chosen language into the signed draft preview and renderer', function (): void {
    test()->actingAsAdmin();
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $theme = Theme::factory()->defaultMeta()->createOne();
    $site = Site::factory()->language($english)->theme($theme)->withTranslations([$english, $french])->createOne();
    $page = Page::factory()->site($site)->withTranslations([$english, $french])->createOne();
    $renderer = Mockery::mock(ThemePreviewRendererInterface::class);
    $renderer->shouldReceive('render')->once()->withArgs(fn (Theme $theme, Site $site, Page $record, ?Language $language = null, ?SiteDomain $siteDomain = null): bool => $record->is($page) && $language?->is($french) === true && $siteDomain?->language_id === $french->id)
        ->andReturn(new Response('French preview'));
    app()->instance(ThemePreviewRendererInterface::class, $renderer);

    $url = IssuePagePreviewTokenAction::run($page, languageId: $french->id);
    expect($url)->toContain('language_id=' . $french->id);
    test()->get($url)->assertOk()->assertSee('French preview');
    test()->get(str_replace('language_id=' . $french->id, 'language_id=' . $english->id, $url))->assertForbidden();
});

it('explains an unavailable signed preview language without rendering another translation', function (): void {
    test()->actingAsAdmin();
    $english = Language::factory()->english()->createOne();
    $foreign = Language::factory()->french()->createOne();
    $theme = Theme::factory()->defaultMeta()->createOne();
    $site = Site::factory()->language($english)->theme($theme)->withTranslations($english)->createOne();
    $page = Page::factory()->site($site)->withTranslations($english)->createOne();
    $renderer = Mockery::mock(ThemePreviewRendererInterface::class);
    $renderer->shouldNotReceive('render');

    app()->instance(ThemePreviewRendererInterface::class, $renderer);
    $url = URL::temporarySignedRoute('capell.admin.preview-page', now()->addMinutes(10), [
        'page' => $page->id,
        'language_id' => $foreign->id,
    ]);

    test()->getJson($url)->assertUnprocessable()
        ->assertJsonPath('message', __('capell-admin::page_journey.missing_translation'));
});

it('retains preview access to assigned sites outside the current authoring scope', function (): void {
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $theme = Theme::factory()->defaultMeta()->createOne();
    $site = Site::factory()->language($english)->theme($theme)->withTranslations([$english, $french])->createOne();
    $authoring = Site::factory()->language($english)->theme($theme)->withTranslations([$english])->createOne();
    $page = Page::factory()->site($site)->withTranslations([$english, $french])->createOne();
    $actor = createPagePreviewSiteEditor([$site, $authoring], canViewPages: true);

    $renderer = Mockery::mock(ThemePreviewRendererInterface::class);
    $renderer->shouldReceive('render')->once()->withArgs(fn (Theme $theme, Site $site, Page $page, ?Language $language = null): bool => $language?->is($french) === true)
        ->andReturn(new Response('Assigned site French preview'));
    app()->instance(ThemePreviewRendererInterface::class, $renderer);
    $url = URL::temporarySignedRoute('capell.admin.preview-page', now()->addMinutes(10), [
        'page' => $page->id,
        'language_id' => $french->id,
    ]);

    // The editor is authoring another of their sites; the preview is scoped to the page's own site.
    test()->actingAs($actor)
        ->withSession(['capell.current_site_id' => $authoring->id])
        ->get($url)
        ->assertOk()
        ->assertSee('Assigned site French preview');
});

it('enforces lockdown on a valid signed page preview', function (): void {
    $theme = Theme::factory()->defaultMeta()->createOne();
    $site = Site::factory()->theme($theme)->withTranslations()->createOne();
    $page = Page::factory()->site($site)->withTranslations(languages: $site->language)->createOne();
    $renderer = Mockery::mock(ThemePreviewRendererInterface::class);
    $renderer->shouldReceive('render')->once()->andReturn(new Response('preview available'));
    app()->instance(ThemePreviewRendererInterface::class, $renderer);
    config(['capell.lockdown.file' => storage_path('framework/testing/page-preview-lockdown.json')]);
    test()->actingAsAdmin();
    $owner = test()->authenticatedUser();
    $blocked = test()->createUserWithRole('super_admin');
    $store = resolve(LockdownStore::class);
    try {
        $store->activateFor($owner);
        $url = IssuePagePreviewTokenAction::run($page);
        test()->actingAs($blocked)->get($url)->assertStatus(423);
        auth()->logout();
        test()->get($url)->assertRedirect('/admin/login');
        test()->actingAs($owner)->get($url)->assertOk()->assertSee('preview available');
    } finally {
        $store->deactivate();
    }
});

it('refuses a signed draft preview to a site editor who may not view its pages', function (string $preview): void {
    $theme = Theme::factory()->defaultMeta()->createOne();
    $site = Site::factory()->theme($theme)->withTranslations()->createOne();
    $page = Page::factory()->site($site)->withTranslations(languages: $site->language)->createOne();
    app()->instance(ThemePreviewRendererInterface::class, new class implements ThemePreviewRendererInterface
    {
        #[Override]
        public function render(Theme $theme, Site $site, Page $page, ?Language $language = null, ?SiteDomain $siteDomain = null): Response
        {
            return new Response('draft body');
        }
    });

    test()->actingAsAdmin();
    $url = $preview === 'page'
        ? IssuePagePreviewTokenAction::run($page)
        : CreateThemePreviewUrlAction::run($theme, $site, $page);

    test()->actingAs(createPagePreviewSiteEditor([$site], canViewPages: true))
        ->get($url)->assertOk()->assertSee('draft body');
    session()->flush();
    test()->actingAs(createPagePreviewSiteEditor([$site], canViewPages: false))
        ->get($url)->assertForbidden()->assertDontSee('draft body');
})->with(['page', 'theme']);

it('offers the draft preview only to editors who may view the page', function (): void {
    $site = Site::factory()->withTranslations()->createOne();
    $page = Page::factory()->site($site)->withTranslations(languages: $site->language)->createOne(['visible_from' => now()->addWeek()]);

    test()->actingAs(createPagePreviewSiteEditor([$site], canViewPages: true));
    // The page editor runs under the page's site scope.
    setPermissionsTeamId($site->getKey());
    expect(PreviewDraftPageHeaderAction::make()->record($page)->isVisible())->toBeTrue();

    // Without View:Page the signed preview answers 403, so the editor is not offered it.
    test()->actingAs(createPagePreviewSiteEditor([$site], canViewPages: false));
    setPermissionsTeamId($site->getKey());
    expect(PreviewDraftPageHeaderAction::make()->record($page)->isVisible())->toBeFalse();
});

it('sends a signed-out visitor to the panel sign-in before the preview signature is checked', function (): void {
    $page = Page::factory()->createOne();
    $tampered = IssuePagePreviewTokenAction::run($page) . 'x';

    // The signature middleware alone would answer 403; the panel's own sign-in answers first.
    test()->get($tampered)->assertRedirect('/admin/login');
    test()->actingAsAdmin()->get($tampered)->assertForbidden();
});

/**
 * A persisted panel user whose only roles are on the given sites, as a site-scoped editor is.
 *
 * @param  list<Site>  $sites
 */
function createPagePreviewSiteEditor(array $sites, bool $canViewPages): TeamScopedPanelUser
{
    $role = Role::query()->firstOrCreate(['name' => $canViewPages ? 'page-preview-viewer' : 'page-preview-outsider', 'guard_name' => 'web']);
    if ($canViewPages) {
        $role->givePermissionTo(Permission::findOrCreate('View:Page', 'web'));
    }

    // The panel re-reads the signed-in user through the auth provider, so it must load the site-scoped model, as a host's does.
    config(['auth.providers.users.model' => TeamScopedPanelUser::class, 'permission.teams' => true]);
    resolve(PermissionRegistrar::class)->teams = true;
    resolve(AuthFactory::class)->forgetGuards();

    $user = TeamScopedPanelUser::query()->create(['name' => 'Site editor', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);
    foreach ($sites as $site) {
        $user->assignRoleForSite($site, $role);
    }

    setPermissionsTeamId(null);
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh() ?? $user;
}
