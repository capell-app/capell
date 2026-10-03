<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\IssuePagePreviewTokenAction;
use Capell\Admin\Actions\Themes\CreateThemePreviewUrlAction;
use Capell\Admin\Filament\Imports\RedirectImporter;
use Capell\Admin\Tests\Support\Models\TeamScopedPanelUser;
use Capell\Core\Contracts\Themes\ThemePreviewRendererInterface;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Permissions\SiteAccess;
use Filament\Actions\ActionsServiceProvider;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    config(['permission.teams' => true]);
    resolve(PermissionRegistrar::class)->teams = true;
    $this->site = Site::factory()->withTranslations()->create();
    $this->foreign = Site::factory()->withTranslations()->create();
    $this->actor = TeamScopedPanelUser::query()->create(['name' => 'Scoped panel user', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);
    $this->actor->assignRoleForSite($this->site, Role::query()->firstOrCreate(['name' => 'preview-editor', 'guard_name' => 'web']));
    setPermissionsTeamId(null);
});

afterEach(function (): void {
    setPermissionsTeamId(null);
    resolve(PermissionRegistrar::class)->teams = false;
    config(['permission.teams' => false]);
});

it('renders signed previews for assigned sites without a team while denying foreign sites', function (string $preview): void {
    $renderer = new class implements ThemePreviewRendererInterface
    {
        #[Override]
        public function render(Theme $theme, Site $site, Page $page, ?Language $language = null, ?SiteDomain $siteDomain = null): Response
        {
            return new Response('Scoped draft preview');
        }
    };
    app()->instance(ThemePreviewRendererInterface::class, $renderer);
    test()->actingAs($this->actor);
    expect(SiteAccess::forActor($this->actor)->allowedSiteIds())->toBe([]);
    foreach ([[$this->site, 200], [$this->foreign, 403]] as [$site, $status]) {
        $page = Page::factory()->site($site)->withTranslations()->create();
        $url = $preview === 'page'
            ? IssuePagePreviewTokenAction::run($page)
            : CreateThemePreviewUrlAction::run($site->theme, $site, $page);
        $response = test()->get($url)->assertStatus($status);
        if ($status === 200) {
            $response->assertSee('Scoped draft preview')->assertDontSee('data-capell-editor')->assertDontSee('signedEditorUrl');
            expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
        }
    }
})->with(['page', 'theme']);

it('imports redirects for the queued owner without authentication or a team', function (): void {
    (require dirname((string) new ReflectionClass(ActionsServiceProvider::class)->getFileName(), 2) . '/database/migrations/create_imports_table.php')->up();
    auth()->logout();
    app()->instance(Authenticatable::class, $this->actor);
    $import = new Import;
    $import->forceFill(['file_name' => 'redirects.csv', 'file_path' => 'redirects.csv', 'importer' => RedirectImporter::class, 'total_rows' => 2]);
    $import->user()->associate($this->actor);
    $import->save();
    $import = $import->fresh();
    throw_unless($import instanceof Import, LogicException::class, 'Expected the persisted import');
    $importer = new RedirectImporter($import, ['url' => 'url', 'target_url' => 'target_url'], ['site_id' => $this->site->id, 'language_id' => $this->site->language_id]);
    $importer(['url' => '/queued-old', 'target_url' => '/queued-new']);
    expect(PageUrl::query()->where('site_id', $this->site->id)->where('url', '/queued-old')->exists())->toBeTrue();
    $foreign = new RedirectImporter($import, ['url' => 'url', 'target_url' => 'target_url'], ['site_id' => $this->foreign->id, 'language_id' => $this->foreign->language_id]);
    expect(fn () => $foreign(['url' => '/foreign-old', 'target_url' => '/foreign-new']))->toThrow(ValidationException::class);
    expect(PageUrl::query()->where('url', '/foreign-old')->exists())->toBeFalse();
});
