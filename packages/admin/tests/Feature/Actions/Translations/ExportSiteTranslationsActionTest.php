<?php

declare(strict_types=1);

use Capell\Admin\Actions\Translations\ExportSiteTranslationsAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @return list<list<string>>
 */
function readExportedCsv(StreamedResponse $response): array
{
    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    $csv = str_starts_with($csv, "\u{FEFF}") ? substr($csv, strlen("\u{FEFF}")) : $csv;

    $handle = fopen('php://temp', 'r+b');

    if ($handle === false) {
        throw new RuntimeException('Unable to open a temporary stream to read the exported CSV.');
    }

    fwrite($handle, $csv);
    rewind($handle);

    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        /** @var list<string> $row */
        $rows[] = $row;
    }

    fclose($handle);

    return $rows;
}

/**
 * @return array{0: Site, 1: Language, 2: Language}
 */
function makeTwoLanguageSite(): array
{
    $english = Language::factory()->createOne(['code' => 'en', 'default' => true]);
    $german = Language::factory()->createOne(['code' => 'de', 'default' => false]);

    Language::query()->whereKeyNot($english->id)->update(['default' => false]);

    $site = Site::factory()->createOne();

    Translation::factory()->translatable($site)->language($english)->createOne(['title' => 'Site EN']);
    Translation::factory()->translatable($site)->language($german)->createOne(['title' => 'Site DE']);

    return [$site, $english, $german];
}

it('exports one row per record and target language with the expected columns', function (): void {
    [$site, $english, $german] = makeTwoLanguageSite();

    $page = Page::factory()->createOne(['site_id' => $site->id, 'name' => 'Landing']);
    Translation::factory()->translatable($page)->language($english)->createOne([
        'title' => 'Landing EN',
        'content' => 'Source body',
    ]);

    $rows = readExportedCsv(ExportSiteTranslationsAction::run($site));

    expect($rows[0])->toBe(ExportSiteTranslationsAction::COLUMNS);

    $pageRows = array_values(array_filter(
        array_slice($rows, 1),
        fn (array $row): bool => $row[1] === (string) $page->id && $row[0] === $page->getMorphClass(),
    ));

    expect($pageRows)->toHaveCount(1);
    expect($pageRows[0][2])->toBe('Landing');
    expect($pageRows[0][3])->toBe('');
    expect($pageRows[0][4])->toBe('en');
    expect($pageRows[0][5])->toBe('Landing EN');
    expect($pageRows[0][6])->toBe('Source body');
    expect($pageRows[0][8])->toBe('de');
    expect($pageRows[0][10])->toBe('');

    expect(array_slice($rows, 1))->toHaveCount(2);
    expect($english->code)->toBe('en')
        ->and($german->code)->toBe('de');
});

it('round trips content containing commas, double quotes and newlines', function (): void {
    [$site, $english, $german] = makeTwoLanguageSite();

    $content = "Line one, with comma\nLine \"two\" quoted\r\nLine three";

    $page = Page::factory()->createOne(['site_id' => $site->id, 'name' => 'Tricky, "page"']);
    Translation::factory()->translatable($page)->language($english)->createOne([
        'title' => 'Source, "title"',
        'content' => $content,
    ]);
    Translation::factory()->translatable($page)->language($german)->createOne([
        'title' => 'Ziel, "Titel"',
        'content' => $content,
    ]);

    $rows = readExportedCsv(ExportSiteTranslationsAction::run($site, $german));

    $pageRow = array_values(array_filter(
        array_slice($rows, 1),
        fn (array $row): bool => $row[1] === (string) $page->id && $row[0] === $page->getMorphClass(),
    ))[0];

    expect($pageRow[2])->toBe('Tricky, "page"');
    expect($pageRow[5])->toBe('Source, "title"');
    expect($pageRow[6])->toBe($content);
    expect($pageRow[9])->toBe('Ziel, "Titel"');
    expect($pageRow[10])->toBe($content);
});

it('scopes the export to the requested site', function (): void {
    [$site, $english] = makeTwoLanguageSite();

    $ownPage = Page::factory()->createOne(['site_id' => $site->id]);
    Translation::factory()->translatable($ownPage)->language($english)->createOne(['title' => 'Mine']);

    $otherSite = Site::factory()->createOne();
    $otherPage = Page::factory()->createOne(['site_id' => $otherSite->id]);
    Translation::factory()->translatable($otherPage)->language($english)->createOne(['title' => 'Theirs']);

    $rows = array_slice(readExportedCsv(ExportSiteTranslationsAction::run($site)), 1);

    $identities = array_map(fn (array $row): string => $row[0] . ':' . $row[1], $rows);

    expect($identities)->toContain($site->getMorphClass() . ':' . $site->id)
        ->toContain($ownPage->getMorphClass() . ':' . $ownPage->id)
        ->not->toContain($otherPage->getMorphClass() . ':' . $otherPage->id);
});
