<?php

declare(strict_types=1);

use Capell\Admin\Actions\Translations\ExportSiteTranslationsAction;
use Capell\Admin\Filament\Resources\Languages\Pages\ManageLanguages;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(CreatesAdminUser::class)
    ->group('language');

beforeEach(function (): void {
    Gate::before(fn (): bool => true);
    test()->actingAsAdmin();
});

/**
 * @return array{0: Site, 1: Language, 2: Language}
 */
function exportActionSiteWithFrench(): array
{
    $english = Language::factory()->createOne(['code' => 'en', 'name' => 'English', 'default' => true]);
    $french = Language::factory()->createOne(['code' => 'fr', 'name' => 'French', 'default' => false]);

    Language::query()->whereKeyNot($english->id)->update(['default' => false]);

    $site = Site::factory()
        ->state(['language_id' => $english->id])
        ->has(SiteDomain::factory()->state(['language_id' => $english->id]))
        ->has(SiteDomain::factory()->state(['language_id' => $french->id]))
        ->create();

    return [$site, $english, $french];
}

it('names the source language and exports source and target rows for a chosen target language', function (): void {
    [$site, , $french] = exportActionSiteWithFrench();

    Livewire::test(ManageLanguages::class)
        ->mountAction('export-translations')
        ->assertMountedActionModalSee(__('capell-admin::exchanger.export_translations.source_language_help', ['language' => 'English']))
        ->setActionData(['site_id' => $site->id, 'language_id' => $french->id])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertFileDownloaded();
});

it('does not accept the source language as a translation target', function (): void {
    [$site, $english] = exportActionSiteWithFrench();

    Livewire::test(ManageLanguages::class)
        ->callAction('export-translations', data: ['site_id' => $site->id, 'language_id' => $english->id])
        ->assertHasActionErrors(['language_id'])
        ->assertNoFileDownloaded();
});

it('explains an all-languages export that would be empty instead of downloading a header-only file', function (): void {
    $english = Language::factory()->createOne(['code' => 'en', 'name' => 'English', 'default' => true]);
    Language::query()->whereKeyNot($english->id)->update(['default' => false]);
    Language::query()->whereKeyNot($english->id)->delete();

    $site = Site::factory()
        ->state(['language_id' => $english->id])
        ->has(SiteDomain::factory()->state(['language_id' => $english->id]))
        ->create();

    Livewire::test(ManageLanguages::class)
        ->callAction('export-translations', data: ['site_id' => $site->id])
        ->assertNotified(__('capell-admin::exchanger.export_translations.nothing_to_export_title'))
        ->assertNoFileDownloaded();
});

it('reports whether a site has any target language besides the source language', function (): void {
    [$site, $english, $french] = exportActionSiteWithFrench();

    $action = ExportSiteTranslationsAction::make();

    expect($action->hasTargetLanguages($site))->toBeTrue()
        ->and($action->hasTargetLanguages($site, $french))->toBeTrue()
        ->and($action->hasTargetLanguages($site, $english))->toBeFalse();
});
