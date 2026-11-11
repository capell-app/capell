<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Livewire;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    test()->actingAsAdmin();
});

/**
 * A site whose default language is English, with a page translated into French
 * first and English second, so an unconstrained read returns the French row.
 *
 * @return array{Site, Page}
 */
function titleLanguageFixture(): array
{
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $site = Site::factory()->language($english)->createOne(['name' => 'Site record name']);
    $site->translations()->delete();
    Translation::factory()->translatable($site)->language($french)->createOne(['title' => 'Site en français']);
    Translation::factory()->translatable($site)->language($english)->createOne(['title' => 'Site in English']);

    $page = Page::factory()->site($site)->createOne(['name' => 'Page record name']);
    $page->translations()->delete();
    Translation::factory()->translatable($page)->language($french)->createOne(['title' => 'Page en français']);
    Translation::factory()->translatable($page)->language($english)->createOne(['title' => 'Page in English']);

    return [$site, $page];
}

it('describes a page search result with its default-language title', function (): void {
    [, $page] = titleLanguageFixture();

    $record = PageResource::getGlobalSearchEloquentQuery()->whereKey($page->getKey())->firstOrFail();
    expect($record)->toBeInstanceOf(Page::class);
    $details = collect($record instanceof Page ? PageResource::getGlobalSearchResultDetails($record) : [])
        ->map(fn (Htmlable|string $detail): string => $detail instanceof Htmlable ? $detail->toHtml() : $detail)
        ->all();

    expect($details)->toContain('Page in English')
        ->and($details)->not->toContain('Page en français');
});

it('describes a site search result with its default-language title', function (): void {
    [$site] = titleLanguageFixture();

    $record = SiteResource::getGlobalSearchEloquentQuery()->whereKey($site->getKey())->firstOrFail();
    expect($record)->toBeInstanceOf(Site::class);
    $details = collect($record instanceof Site ? SiteResource::getGlobalSearchResultDetails($record) : [])
        ->map(fn (Htmlable|string $detail): string => $detail instanceof Htmlable ? $detail->toHtml() : $detail)
        ->all();

    expect(implode(' ', $details))->toContain('Site in English')
        ->not->toContain('Site en français');
});

it('lists pages with their default-language translation when no language filter is set', function (): void {
    [, $page] = titleLanguageFixture();

    $records = Livewire::test(ListPages::class)->instance()->getTableRecords();
    $listed = collect($records->items())->firstWhere('id', $page->getKey());

    expect($listed)->toBeInstanceOf(Page::class)
        ->and($listed->title)->toBe('Page in English');
});

/**
 * A site whose default language (German) is not the installation default (English),
 * with the page's English row written first.
 *
 * @return array{Site, Page, Language, Language}
 */
function siteDefaultTitleFixture(): array
{
    $english = Language::factory()->english()->createOne(['default' => true]);
    $german = Language::factory()->german()->createOne(['default' => false]);
    $site = Site::factory()->language($german)->createOne();
    $page = Page::factory()->site($site)->createOne(['name' => 'Page record name']);
    $page->translations()->forceDelete();
    Translation::factory()->translatable($page)->language($english)->createOne(['title' => 'Page in English']);
    Translation::factory()->translatable($page)->language($german)->createOne(['title' => 'Seite auf Deutsch']);

    return [$site, $page, $english, $german];
}

it('shows each page in its own site default language when no language filter is set', function (): void {
    [, $page] = siteDefaultTitleFixture();

    Livewire::test(ListPages::class)
        ->toggleAllTableColumns()
        ->assertTableColumnStateSet('translation.title', 'Seite auf Deutsch', $page);
});

it('shows the filtered language title when a language filter is set', function (): void {
    [, $page, $english] = siteDefaultTitleFixture();

    Livewire::test(ListPages::class)
        ->toggleAllTableColumns()
        ->filterTable('filter', ['language_id' => $english->getKey()])
        ->assertTableColumnStateSet('translation.title', 'Page in English', $page);
});
