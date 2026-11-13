<?php

declare(strict_types=1);

use Capell\Core\Actions\BuildCapellSiteFromSpecAction;
use Capell\Core\Actions\CreateDefaultLanguagesAction;
use Capell\Core\Actions\CreateSiteAction;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    CreateDefaultLanguagesAction::run(['en']);
    Queue::fake();
});

function nameClashSpec(string $name): CapellSiteSpecData
{
    return CapellSiteSpecData::validateAndCreate([
        'site' => ['name' => $name, 'businessName' => 'Imported Business'],
        'theme' => ['key' => 'default', 'colors' => ['primary' => '#123456']],
        'pages' => [[
            'name' => 'Home',
            'slug' => 'home',
            'title' => 'Home title',
            'pageType' => 'default',
            'order' => 0,
            'sections' => [['type' => 'content', 'content' => '<p>Imported copy.</p>']],
        ]],
        'initialVisibility' => 'private',
        'acknowledgePublic' => false,
    ]);
}

it('refuses a spec whose site name belongs to an unrelated site and leaves that site untouched', function (): void {
    $language = Language::query()->where('code', 'en')->firstOrFail();
    $unrelated = CreateSiteAction::run('Harbour Books', 'https://harbour.test', $language);
    $metaBefore = $unrelated->refresh()->meta;
    $pagesBefore = $unrelated->pages()->count();
    $sitesBefore = Site::query()->count();

    expect(fn (): Site => BuildCapellSiteFromSpecAction::run(nameClashSpec('Harbour Books')))
        ->toThrow(RuntimeException::class, 'Harbour Books');

    $unrelated->refresh();
    expect($unrelated->meta)->toBe($metaBefore)
        ->and($unrelated->meta)->not->toHaveKey('site_spec_hash')
        ->and($unrelated->pages()->count())->toBe($pagesBefore)
        ->and(Site::query()->count())->toBe($sitesBefore);
});

it('still returns the site it built earlier when the same spec is imported again', function (): void {
    $first = BuildCapellSiteFromSpecAction::run(nameClashSpec('Harbour Books'));
    $second = BuildCapellSiteFromSpecAction::run(nameClashSpec('Harbour Books'));

    expect($second->is($first))->toBeTrue()
        ->and(Site::query()->where('name', 'Harbour Books')->count())->toBe(1);
});
