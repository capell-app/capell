<?php

declare(strict_types=1);

use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Core\Support\CapellCoreHelper;
use Capell\Core\Tests\Support\Fixtures\Autoload\CapellCoreHelperRelationProbe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

beforeEach(function (): void {
    CapellCoreHelper::flushCache();
});

it('resolves and clears cached type lookups by id and query filter', function (): void {
    $pageType = Blueprint::factory()->page()->create([
        'key' => 'article',
        'name' => 'Article',
    ]);

    Blueprint::factory()->site()->create([
        'key' => 'site-record',
        'name' => 'Site record',
    ]);

    expect(CapellCoreHelper::getBlueprint((string) $pageType->getKey())?->is($pageType))->toBeTrue()
        ->and(CapellCoreHelper::getBlueprint(null, ['type' => BlueprintSubjectEnum::Page->value])?->key)->toBe('article');

    expect(CapellCoreHelper::getBlueprint(null, ['key' => 'later']))->toBeNull();

    Blueprint::factory()->page()->create([
        'key' => 'later',
        'name' => 'Later',
    ]);

    CapellCoreHelper::clearType(null, ['key' => 'later']);

    expect(CapellCoreHelper::getBlueprint(null, ['key' => 'later'])?->name)->toBe('Later');
});

it('uses callable blueprint filters as independent cache entries', function (): void {
    $article = Blueprint::factory()->page()->create([
        'key' => 'article',
        'name' => 'Article',
    ]);
    $landing = Blueprint::factory()->page()->create([
        'key' => 'landing',
        'name' => 'Landing',
    ]);

    $landingFilter = new class
    {
        public function __invoke(Builder $query): void
        {
            $query->where('key', 'landing');
        }
    };

    expect(CapellCoreHelper::getBlueprint(null, $landingFilter)?->is($landing))->toBeTrue();

    Blueprint::query()
        ->whereKey($landing->getKey())
        ->update(['name' => 'Updated landing']);

    expect(CapellCoreHelper::getBlueprint(null, $landingFilter)?->name)->toBe('Landing');

    CapellCoreHelper::clearType(null, $landingFilter);

    expect(CapellCoreHelper::getBlueprint(null, $landingFilter)?->name)->toBe('Updated landing')
        ->and(CapellCoreHelper::getBlueprint(null, ['key' => 'article'])?->is($article))->toBeTrue();
});

it('falls back to the default site and returns null when fallback is disabled', function (): void {
    $defaultSite = Site::factory()->default()->create(['name' => 'Default site']);

    expect(CapellCoreHelper::getSite(null)?->is($defaultSite))->toBeTrue()
        ->and(CapellCoreHelper::getSite('0')?->is($defaultSite))->toBeTrue()
        ->and(CapellCoreHelper::getSite(123456, fallbackToDefault: false))->toBeNull()
        ->and(CapellCoreHelper::hasDefaultSite())->toBeTrue();
});

it('returns ordered site languages from a record relation before falling back globally', function (): void {
    $english = Language::factory()->english(order: 2)->create();
    $french = Language::factory()->french(order: 1)->create();
    $german = Language::factory()->german(order: 3)->create();
    $site = Site::factory()->withTranslations([$english, $french])->create([
        'language_id' => $english->getKey(),
    ]);
    $page = Page::factory()->site($site)->create();
    $page->setRelation('site', $site);

    expect(CapellCoreHelper::getSiteLanguagesForRecord($page, $site->getKey())->pluck('code')->all())
        ->toBe(['fr', 'en']);

    expect(CapellCoreHelper::getSiteLanguagesForRecord(null)->pluck('code')->all())
        ->toBe(['fr', 'en', 'de']);

    expect(CapellCoreHelper::getLanguageByIdOrSite(null, $site->getKey())?->code)->toBe('en')
        ->and(CapellCoreHelper::getLanguageByIdOrSite($german->getKey(), $site->getKey())?->code)->toBe('de');
});

it('resolves site languages from translations and explicit site fallbacks', function (): void {
    $english = Language::factory()->english(order: 2)->create();
    $french = Language::factory()->french(order: 1)->create();
    $german = Language::factory()->german(order: 3)->create();
    $site = Site::factory()->withTranslations([$english, $french])->create([
        'language_id' => $english->getKey(),
    ]);
    $otherSite = Site::factory()->withTranslations($german)->create([
        'language_id' => $german->getKey(),
    ]);
    $page = Page::factory()->site($site)->create();
    $translation = Translation::factory()
        ->language($english)
        ->translatable($page)
        ->create();

    expect(CapellCoreHelper::getSiteLanguagesForRecord($translation, $site->getKey())->pluck('code')->all())
        ->toBe(['fr', 'en'])
        ->and(CapellCoreHelper::getSiteLanguagesForRecord(null, $otherSite->getKey())->pluck('code')->all())
        ->toBe(['de']);
});

it('checks cached site type defaults and flushes enum-prefixed cache entries', function (): void {
    Blueprint::factory()->page()->create();
    Blueprint::factory()->site()->create();
    Blueprint::factory()->theme()->create();

    $english = Language::factory()->english()->create();
    $defaultSite = Site::factory()->withTranslations($english)->default()->create([
        'name' => 'Default site',
        'language_id' => $english->getKey(),
    ]);
    Site::factory()->create(['name' => 'Archive site']);
    Theme::factory()->default()->create(['default' => true]);

    expect(CapellCoreHelper::hasSiteType())->toBeTrue()
        ->and(CapellCoreHelper::missingDefaultTypes())->toBeFalse()
        ->and(CapellCoreHelper::hasDefaultLanguage())->toBeTrue()
        ->and(CapellCoreHelper::hasFoundationTheme())->toBeTrue()
        ->and(CapellCoreHelper::modelDefaultExists(Theme::class))->toBeTrue()
        ->and(CapellCoreHelper::getSites(fn (Builder $query): Builder => $query->where('name', 'Archive site'))->pluck('name')->all())
        ->toBe(['Archive site']);

    expect(CapellCoreHelper::getSite(null)?->name)->toBe('Default site');

    Site::query()
        ->whereKey($defaultSite->getKey())
        ->update(['name' => 'Renamed default']);

    expect(CapellCoreHelper::getSite(null)?->name)->toBe('Default site');

    CapellCoreHelper::flushCache(CacheEnum::Site);

    expect(CapellCoreHelper::getSite(null)?->name)->toBe('Renamed default');
});

it('checks model defaults relations and language code lookups through the helper cache', function (): void {
    $english = Language::factory()->english()->create();
    $french = Language::factory()->french()->create();
    $site = Site::factory()->withTranslations($english)->create([
        'default' => true,
        'language_id' => $english->getKey(),
    ]);

    expect(CapellCoreHelper::modelDefaultExists(Site::class))->toBeTrue()
        ->and(CapellCoreHelper::modelDefaultExists(Model::class))->toBeFalse()
        ->and(CapellCoreHelper::relationExists($site, 'missingRelation'))->toBeFalse()
        ->and(CapellCoreHelper::relationExists(new CapellCoreHelperRelationProbe, 'notARelation'))->toBeFalse()
        ->and(CapellCoreHelper::getLanguageCodesByIds([$french->getKey(), $english->getKey()]))->toEqualCanonicalizing(['en', 'fr'])
        ->and(CapellCoreHelper::languagesByCodes(['fr', 'missing'])->pluck('code')->all())->toBe(['fr']);
});
