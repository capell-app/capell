<?php

declare(strict_types=1);

use Capell\Admin\Filament\Imports\RedirectImporter;
use Capell\Admin\Tests\Support\Models\TeamScopedPanelUser;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Support\TableName;
use Filament\Actions\ActionsServiceProvider;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    config(['permission.teams' => true]);
    resolve(PermissionRegistrar::class)->teams = true;
});

afterEach(function (): void {
    setPermissionsTeamId(null);
    resolve(PermissionRegistrar::class)->teams = false;
    config(['permission.teams' => false]);
});

/** A signed-in admin who may import redirects on the given sites only. */
function signInRedirectImporterForRedirectImporterTest(Site ...$sites): TeamScopedPanelUser
{
    $user = TeamScopedPanelUser::query()->create(['name' => 'Redirect Importer User', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);
    $role = Role::query()->firstOrCreate(['name' => 'redirect-importer', 'guard_name' => 'web']);
    $role->givePermissionTo(Permission::findOrCreate('Import:PageUrl', 'web'));
    foreach ($sites as $site) {
        $user->assignRoleForSite($site, $role);
    }

    setPermissionsTeamId(null);
    test()->actingAs($user);

    return $user;
}

/** An import started in the Capell admin panel by the signed-in user, as the import action records it. */
function startedRedirectImportForRedirectImporterTest(): Import
{
    if (! Schema::hasTable('imports')) {
        (require dirname((string) new ReflectionClass(ActionsServiceProvider::class)->getFileName(), 2) . '/database/migrations/create_imports_table.php')->up();
    }

    $user = auth()->user();
    throw_unless($user instanceof TeamScopedPanelUser, LogicException::class, 'Expected a signed-in importer.');

    $import = new Import;
    $import->forceFill(['file_name' => 'redirects.csv', 'file_path' => 'redirects.csv', 'importer' => RedirectImporter::class, 'total_rows' => 1, 'user_id' => $user->getKey()])->save();
    DB::table(TableName::resolve('admin_transfer_origins'))->insert(['transfer_type' => 'import', 'transfer_key' => (string) $import->getKey(), 'panel' => 'admin', 'host' => 'localhost']);

    return $import;
}

function runRedirectImporterForRedirectImporterTest(int $siteId, int $languageId, string $targetUrl = '/new-url'): void
{
    $importer = new RedirectImporter(
        startedRedirectImportForRedirectImporterTest(),
        [
            'url' => 'url',
            'target_url' => 'target_url',
        ],
        [
            'site_id' => $siteId,
            'language_id' => $languageId,
        ],
    );

    $importer([
        'url' => '/old-url',
        'target_url' => $targetUrl,
    ]);
}

it('rejects redirect imports for sites the actor may not import into', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $language = $site->language;
    signInRedirectImporterForRedirectImporterTest(Site::factory()->withTranslations()->create());

    expect(function () use ($site, $language): void {
        runRedirectImporterForRedirectImporterTest($site->getKey(), $language->getKey());
    })
        ->toThrow(ValidationException::class, __('capell-admin::message.import_owner_not_permitted'));

    expect(PageUrl::query()->where('url', '/old-url')->exists())->toBeFalse();
});

it('rejects redirect imports when the language is not attached to the selected site', function (): void {
    $siteLanguage = Language::factory()->createOne();
    $otherLanguage = Language::factory()->createOne();
    $site = Site::factory()->language($siteLanguage)->withTranslations($siteLanguage)->create();

    signInRedirectImporterForRedirectImporterTest($site);

    expect(function () use ($site, $otherLanguage): void {
        runRedirectImporterForRedirectImporterTest($site->getKey(), $otherLanguage->getKey());
    })
        ->toThrow(ValidationException::class, __('capell-admin::message.redirect_import_invalid_language'));

    expect(PageUrl::query()->where('url', '/old-url')->exists())->toBeFalse();
});

it('rejects redirect imports with unsafe targets', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $language = $site->language;

    signInRedirectImporterForRedirectImporterTest($site);

    expect(function () use ($site, $language): void {
        runRedirectImporterForRedirectImporterTest($site->getKey(), $language->getKey(), 'javascript:alert(1)');
    })
        ->toThrow(ValidationException::class, __('capell-core::message.redirect_target_invalid'));

    expect(PageUrl::query()->where('url', '/old-url')->exists())->toBeFalse();
});
