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
use Capell\Core\Support\TableName;
use Filament\Actions\ActionsServiceProvider;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    config(['permission.teams' => true]);
    resolve(PermissionRegistrar::class)->teams = true;
    $this->site = Site::factory()->withTranslations()->create();
    $this->foreign = Site::factory()->withTranslations()->create();
    $this->actor = TeamScopedPanelUser::query()->create(['name' => 'Scoped panel user', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);
    // Previewing a draft needs permission to view pages on the site, as well as a role there.
    $role = Role::query()->firstOrCreate(['name' => 'preview-editor', 'guard_name' => 'web']);
    $role->givePermissionTo(Permission::findOrCreate('View:Page', 'web'));

    $this->actor->assignRoleForSite($this->site, $role);
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

/** A redirect import started in the admin panel by the scoped owner, waiting for its queued rows. */
function queuedRedirectImportForTeamlessTest(TeamScopedPanelUser $owner): Import
{
    (require dirname((string) new ReflectionClass(ActionsServiceProvider::class)->getFileName(), 2) . '/database/migrations/create_imports_table.php')->up();
    auth()->logout();
    app()->instance(Authenticatable::class, $owner);
    $import = new Import;
    $import->forceFill(['file_name' => 'redirects.csv', 'file_path' => 'redirects.csv', 'importer' => RedirectImporter::class, 'total_rows' => 2]);
    $import->user()->associate($owner);
    $import->save();
    // The import action recorded the Capell admin panel it was started in.
    DB::table(TableName::resolve('admin_transfer_origins'))->insert(['transfer_type' => 'import', 'transfer_key' => (string) $import->getKey(), 'panel' => 'admin', 'host' => 'localhost']);
    $import = $import->fresh();
    throw_unless($import instanceof Import, LogicException::class, 'Expected the persisted import');

    return $import;
}

/** @param array<string, mixed> $options */
function queuedRedirectRowForTeamlessTest(Import $import, array $options, string $url): void
{
    new RedirectImporter($import, ['url' => 'url', 'target_url' => 'target_url'], $options)(['url' => $url, 'target_url' => '/queued-new']);
}

function grantRedirectImportOnSiteForTeamlessTest(TeamScopedPanelUser $owner, Site $site): Role
{
    $role = Role::query()->firstOrCreate(['name' => 'redirect-importer', 'guard_name' => 'web']);
    $role->givePermissionTo(Permission::findOrCreate('Import:PageUrl', 'web'));

    $owner->assignRoleForSite($site, $role);
    setPermissionsTeamId(null);

    return $role;
}

it('imports redirects for the queued owner without authentication or a team', function (): void {
    grantRedirectImportOnSiteForTeamlessTest($this->actor, $this->site);
    $import = queuedRedirectImportForTeamlessTest($this->actor);

    queuedRedirectRowForTeamlessTest($import, ['site_id' => $this->site->id, 'language_id' => $this->site->language_id], '/queued-old');

    expect(PageUrl::query()->where('site_id', $this->site->id)->where('url', '/queued-old')->exists())->toBeTrue();
});

it('imports redirects for a queued global administrator, whose roles hold no site team', function (): void {
    // Global roles are stored without a team, so a site scope would hide them.
    Role::findOrCreate('super_admin', 'web')->givePermissionTo(Permission::findOrCreate('Import:PageUrl', 'web'));
    $this->actor->assignGlobalRole('super_admin');
    $import = queuedRedirectImportForTeamlessTest($this->actor);

    queuedRedirectRowForTeamlessTest($import, ['site_id' => $this->foreign->id, 'language_id' => $this->foreign->language_id], '/global-old');

    expect(PageUrl::query()->where('site_id', $this->foreign->id)->where('url', '/global-old')->exists())->toBeTrue();
});

it('writes nothing once the queued owner loses import on the site before the row runs', function (): void {
    $role = grantRedirectImportOnSiteForTeamlessTest($this->actor, $this->site);
    $import = queuedRedirectImportForTeamlessTest($this->actor);
    $role->revokePermissionTo('Import:PageUrl');

    expect(fn () => queuedRedirectRowForTeamlessTest($import, ['site_id' => $this->site->id, 'language_id' => $this->site->language_id], '/revoked-old'))
        ->toThrow(ValidationException::class, __('capell-admin::message.import_owner_not_permitted'));
    expect(PageUrl::query()->where('url', '/revoked-old')->exists())->toBeFalse();
});

it('writes nothing on a site where the queued owner may not import, even with import on another site', function (): void {
    grantRedirectImportOnSiteForTeamlessTest($this->actor, $this->site);
    // The owner may also see the foreign site, but only imports on their own.
    $this->actor->assignRoleForSite($this->foreign, 'preview-editor');
    setPermissionsTeamId(null);
    $import = queuedRedirectImportForTeamlessTest($this->actor);

    expect(fn () => queuedRedirectRowForTeamlessTest($import, ['site_id' => $this->foreign->id, 'language_id' => $this->foreign->language_id], '/foreign-old'))
        ->toThrow(ValidationException::class, __('capell-admin::message.import_owner_not_permitted'));
    expect(PageUrl::query()->where('url', '/foreign-old')->exists())->toBeFalse();
});

it('writes nothing for a queued row that names no existing site', function (?int $siteId): void {
    grantRedirectImportOnSiteForTeamlessTest($this->actor, $this->site);
    $import = queuedRedirectImportForTeamlessTest($this->actor);
    $options = ['language_id' => $this->site->language_id];
    if ($siteId !== null) {
        $options['site_id'] = $siteId;
    }

    expect(fn () => queuedRedirectRowForTeamlessTest($import, $options, '/siteless-old'))
        ->toThrow(ValidationException::class, __('capell-admin::message.import_site_unknown'));
    expect(PageUrl::query()->where('url', '/siteless-old')->exists())->toBeFalse();
})->with(['no site' => [null], 'a deleted site' => [999_999]]);
