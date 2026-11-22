<?php

declare(strict_types=1);

use Capell\Admin\Actions\Translations\ExportSiteTranslationsAction;
use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Filament\Resources\Languages\Pages\ManageLanguages;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @return array{site: Site, french: Language, open: Page, restricted: Page, role: Spatie\Permission\Contracts\Role}
 */
function translationExportScenario(): array
{
    $english = Language::factory()->createOne(['code' => 'en', 'name' => 'English', 'default' => true]);
    $french = Language::factory()->createOne(['code' => 'fr', 'name' => 'French', 'default' => false]);
    Language::query()->whereKeyNot($english->id)->update(['default' => false]);

    $site = Site::factory()
        ->state(['language_id' => $english->id])
        ->has(SiteDomain::factory()->state(['language_id' => $english->id]))
        ->has(SiteDomain::factory()->state(['language_id' => $french->id]))
        ->create();

    $role = Role::create(['name' => 'legal-reviewer', 'guard_name' => 'web']);
    $restrictedType = Blueprint::factory()->page()->create();
    $restrictedType->roleRestrictions()->create(['role_id' => $role->getKey()]);

    $open = Page::factory()->createOne(['site_id' => $site->id, 'name' => 'Open page']);
    $restricted = Page::factory()->createOne([
        'site_id' => $site->id,
        'blueprint_id' => $restrictedType->getKey(),
        'name' => 'Restricted page',
    ]);

    foreach ([$open, $restricted] as $page) {
        Translation::factory()->translatable($page)->language($english)->createOne([
            'title' => $page->name . ' title',
            'content' => $page->name . ' body',
        ]);
    }

    return ['site' => $site, 'french' => $french, 'open' => $open, 'restricted' => $restricted, 'role' => $role];
}

function translationExportActor(Site $site, bool $canExport): User
{
    foreach (['ViewAny:Page', 'View:Page', 'ViewAny:Language', 'View:Language', CapellPermission::ExportPage->name()] as $name) {
        Permission::findOrCreate($name);
    }

    $user = User::factory()->createOne();
    $user->assignedSiteIds = collect([(int) $site->getKey()]);
    $user->givePermissionTo(array_filter(
        ['ViewAny:Page', 'View:Page', 'ViewAny:Language', 'View:Language', $canExport ? CapellPermission::ExportPage->name() : null],
    ));
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function translationExportCsv(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

it('refuses a translation export from an editor without the page export permission', function (): void {
    $scenario = translationExportScenario();
    test()->actingAs(translationExportActor($scenario['site'], canExport: false));

    expect(fn () => ExportSiteTranslationsAction::run($scenario['site']))
        ->toThrow(AuthorizationException::class);

    Livewire::test(ManageLanguages::class)
        ->assertActionHidden('export-translations')
        ->call('mountAction', 'export-translations')
        ->set('mountedActions.0.data', ['site_id' => $scenario['site']->id, 'language_id' => $scenario['french']->id])
        ->call('callMountedAction')
        ->assertNoFileDownloaded();
});

it('leaves role-restricted pages out of the export for an exporter outside the role', function (): void {
    $scenario = translationExportScenario();
    test()->actingAs(translationExportActor($scenario['site'], canExport: true));

    $csv = translationExportCsv(ExportSiteTranslationsAction::run($scenario['site']));

    expect($csv)->toContain('Open page body')
        ->not->toContain('Restricted page');
});

it('includes role-restricted pages for an exporter inside the role', function (): void {
    $scenario = translationExportScenario();
    $actor = translationExportActor($scenario['site'], canExport: true);
    $actor->assignRole($scenario['role']);

    test()->actingAs($actor);

    $csv = translationExportCsv(ExportSiteTranslationsAction::run($scenario['site']));

    expect($csv)->toContain('Open page body')
        ->toContain('Restricted page body');

    Livewire::test(ManageLanguages::class)
        ->callAction('export-translations', data: ['site_id' => $scenario['site']->id, 'language_id' => $scenario['french']->id])
        ->assertFileDownloaded();
});
