<?php

declare(strict_types=1);

use Capell\Admin\Actions\Translations\ResolveTranslationBoardStatusAction;
use Capell\Admin\Enums\TranslationBoardStatus;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;

it('reports a language with no row as missing', function (): void {
    [$page, $english] = boardStatusPageForTest();
    $default = boardStatusTranslationForTest($page, $english, 'Welcome');

    expect(ResolveTranslationBoardStatusAction::run(null, $default))->toBe(TranslationBoardStatus::Missing);
});

it('reports the default-language row as done', function (): void {
    [$page, $english] = boardStatusPageForTest();
    $default = boardStatusTranslationForTest($page, $english, 'Welcome');

    expect(ResolveTranslationBoardStatusAction::run($default, $default))->toBe(TranslationBoardStatus::Done);
});

it('reports a row still identical to the default language as copied', function (): void {
    [$page, $english, $welsh] = boardStatusPageForTest();
    $default = boardStatusTranslationForTest($page, $english, 'Welcome');
    $copy = boardStatusTranslationForTest($page, $welsh, 'Welcome');

    expect(ResolveTranslationBoardStatusAction::run($copy, $default))->toBe(TranslationBoardStatus::Copied);
});

it('reports a translated row as done until the default language changes, then as needing an update', function (): void {
    [$page, $english, $welsh] = boardStatusPageForTest();
    $default = boardStatusTranslationForTest($page, $english, 'Welcome');
    $welshRow = boardStatusTranslationForTest($page, $welsh, 'Croeso');

    expect(ResolveTranslationBoardStatusAction::run($welshRow, $default))->toBe(TranslationBoardStatus::Done);

    $default->update(['title' => 'Welcome to our shop']);

    expect(ResolveTranslationBoardStatusAction::run($welshRow->refresh(), $default->refresh()))
        ->toBe(TranslationBoardStatus::NeedsUpdate);

    $welshRow->update(['title' => 'Croeso i’n siop']);

    expect(ResolveTranslationBoardStatusAction::run($welshRow->refresh(), $default))->toBe(TranslationBoardStatus::Done);
});

it('falls back to save times for a row saved before field tracking began', function (): void {
    [$page, $english, $welsh] = boardStatusPageForTest();
    $default = boardStatusTranslationForTest($page, $english, 'Welcome');
    $welshRow = boardStatusTranslationForTest($page, $welsh, 'Croeso');

    Translation::query()->whereKey($welshRow->getKey())->update(['source_fingerprints' => null, 'updated_at' => now()->subDay()]);
    Translation::query()->whereKey($default->getKey())->update(['updated_at' => now()]);

    expect(ResolveTranslationBoardStatusAction::run($welshRow->refresh(), $default->refresh()))
        ->toBe(TranslationBoardStatus::NeedsUpdate);

    Translation::query()->whereKey($welshRow->getKey())->update(['updated_at' => now()->addMinute()]);

    expect(ResolveTranslationBoardStatusAction::run($welshRow->refresh(), $default))->toBe(TranslationBoardStatus::Done);
});

/** @return array{0: Page, 1: Language, 2: Language} */
function boardStatusPageForTest(): array
{
    $english = Language::factory()->english()->createOne();
    $welsh = Language::factory()->forCountry('Welsh', 'cy', 'cy', 'gb-wls', order: 2)->createOne();
    $site = Site::factory()->language($english)->createOne();
    $page = Page::factory()->site($site)->type(Blueprint::factory()->page()->createOne())->createOne();

    return [$page, $english, $welsh];
}

function boardStatusTranslationForTest(Page $page, Language $language, string $title): Translation
{
    $page->translations()->where('language_id', $language->getKey())->delete();

    $translation = Translation::factory()->language($language)->make(['title' => $title, 'content' => null, 'meta' => null]);
    $page->translations()->save($translation);

    return $translation->refresh();
}
