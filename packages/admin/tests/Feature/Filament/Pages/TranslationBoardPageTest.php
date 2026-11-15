<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\TranslationBoardPage;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

it('refuses people without the translations permission', function (): void {
    test()->actingAsUser();

    $this->get(TranslationBoardPage::getUrl())->assertForbidden();
});

it('shows each page with its status in every site language', function (): void {
    [$site, $english, $french] = translationBoardSiteForTest();
    grantTranslationBoardAccessForTest();

    $translated = translationBoardPageForTest($site, [$english->getKey() => 'About us', $french->getKey() => 'À propos']);
    $copied = translationBoardPageForTest($site, [$english->getKey() => 'Contact', $french->getKey() => 'Contact']);
    $missing = translationBoardPageForTest($site, [$english->getKey() => 'Services']);

    $component = Livewire::test(TranslationBoardPage::class)
        ->assertSet('site_id', $site->getKey())
        ->assertSeeInOrder(['English', 'Français'])
        ->assertSee('About us')
        ->assertSee('Services');

    $rows = $component->viewData('rows');
    $statusFor = static fn (Page $page): array => collect($rows)
        ->firstWhere('pageId', $page->getKey())
        ->cells;

    expect(array_map(static fn ($cell): string => $cell->status->value, $statusFor($translated)))->toBe(['done', 'done'])
        ->and(array_map(static fn ($cell): string => $cell->status->value, $statusFor($copied)))->toBe(['done', 'copied'])
        ->and(array_map(static fn ($cell): string => $cell->status->value, $statusFor($missing)))->toBe(['done', 'missing']);
});

it('flags a page as needing an update once its default-language title changes', function (): void {
    [$site, $english, $french] = translationBoardSiteForTest();
    grantTranslationBoardAccessForTest();
    $page = translationBoardPageForTest($site, [$english->getKey() => 'About us', $french->getKey() => 'À propos']);

    $page->translations()->where('language_id', $english->getKey())->firstOrFail()->update(['title' => 'About our team']);

    Livewire::test(TranslationBoardPage::class)
        ->assertSee('About our team')
        ->assertSeeHtml('data-status="needs_update"')
        ->assertDontSeeHtml('data-status="copied"');
});

it('explains that a single-language site has nothing to track', function (): void {
    $english = Language::factory()->english()->createOne();
    Site::factory()->language($english)->default()->createOne();
    grantTranslationBoardAccessForTest();

    Livewire::test(TranslationBoardPage::class)
        ->assertSee(__('capell-admin::translation_board.single_language_heading'))
        ->assertDontSeeHtml('<table');
});

it('loads the board with a fixed number of queries however many pages the site has', function (): void {
    [$site, $english, $french] = translationBoardSiteForTest();
    grantTranslationBoardAccessForTest();

    translationBoardPageForTest($site, [$english->getKey() => 'One', $french->getKey() => 'Un']);
    $queriesFor = static function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(TranslationBoardPage::class);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    // The first render warms the permission cache; compare warm renders only.
    $queriesFor();
    $withOnePage = $queriesFor();

    foreach (range(1, 5) as $number) {
        translationBoardPageForTest($site, [$english->getKey() => 'Page ' . $number, $french->getKey() => 'Page ' . $number]);
    }

    expect($queriesFor())->toBe($withOnePage);
});

/** @return array{0: Site, 1: Language, 2: Language} */
function translationBoardSiteForTest(): array
{
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $site = Site::factory()->language($english)->default()->withTranslations([$english, $french])->createOne();

    return [$site, $english, $french];
}

/** @param  array<int, string>  $titles  Title per language id. */
function translationBoardPageForTest(Site $site, array $titles): Page
{
    $page = Page::factory()->site($site)->type(Blueprint::factory()->page()->createOne())->createOne();
    $page->translations()->delete();

    foreach ($titles as $languageId => $title) {
        $page->translations()->save(Translation::factory()->make(['language_id' => $languageId, 'title' => $title, 'content' => null, 'meta' => null]));
    }

    return $page;
}

function grantTranslationBoardAccessForTest(): void
{
    Permission::findOrCreate('View:TranslationBoardPage', 'web');
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:TranslationBoardPage');
}
