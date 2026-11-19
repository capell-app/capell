<?php

declare(strict_types=1);

use Capell\Core\Actions\ApplySiteSpecToSiteAction;
use Capell\Core\Actions\BuildCapellSiteFromSpecAction;
use Capell\Core\Actions\CreateDefaultLanguagesAction;
use Capell\Core\Actions\ExportSiteSpecAction;
use Capell\Core\Actions\PlanSiteSpecAction;
use Capell\Core\Actions\Properties\SetPagePropertyValuesAction;
use Capell\Core\Contracts\SiteSpec\SiteSpecExporter;
use Capell\Core\Data\Properties\PropertyValueData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecNavigationData;
use Capell\Core\Data\SiteSpec\SiteSpecExportData;
use Capell\Core\Data\SiteSpec\SiteSpecExportPartData;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Capell\Core\Models\Site;
use Capell\Core\Support\SiteSpec\SiteSpecExporterRegistry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    CreateDefaultLanguagesAction::run(['en']);
    Queue::fake();
    Storage::fake('public', ['url' => 'https://93.184.216.34/storage']);
    config()->set('media-library.disk_name', 'public');
    config()->set('media-library.queue_conversions_by_default', true);
});

function partsSite(): Site
{
    return BuildCapellSiteFromSpecAction::run(CapellSiteSpecData::validateAndCreate([
        'site' => ['name' => 'Harbour Garage'],
        'theme' => ['key' => 'default'],
        'pages' => [
            ['name' => 'Home', 'slug' => 'home', 'title' => 'Welcome', 'pageType' => 'default', 'order' => 0, 'sections' => [['type' => 'content', 'content' => '<p>Home.</p>']]],
            ['name' => 'About', 'slug' => 'about', 'title' => 'About us', 'pageType' => 'default', 'order' => 1, 'sections' => [['type' => 'content', 'content' => '<p>About.</p>']]],
        ],
        'initialVisibility' => 'public',
        'acknowledgePublic' => true,
    ]));
}

function partsPage(Site $site, string $slug): Page
{
    return $site->pages()->with('translations')->get()
        ->first(static fn (Page $page): bool => ($page->translations->first()?->meta['slug'] ?? null) === $slug) ?? throw new RuntimeException('Missing page ' . $slug);
}

function partsPng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z3h8AAAAASUVORK5CYII=', true);
}

function partsProperty(Site $site, string $key, PropertyType $type, mixed $value, bool $multiple = false): void
{
    $set = PropertySet::query()->firstOrCreate(['key' => 'garage.details'], ['name' => 'Garage']);
    $definition = PropertyDefinition::factory()->create(['property_set_id' => $set->id, 'key' => $key, 'type' => $type, 'multiple' => $multiple]);
    BlueprintPropertySet::query()->firstOrCreate([
        'blueprint_id' => Blueprint::query()->pageType()->where('key', 'default')->firstOrFail()->id,
        'property_set_id' => $set->id,
    ]);
    SetPagePropertyValuesAction::run(partsPage($site, 'about'), [new PropertyValueData($definition->key, $type, $value)]);
}

it('exports page properties and applying them back plans nothing, then plans and writes a changed value', function (): void {
    $site = partsSite();
    partsProperty($site, 'sku', PropertyType::Text, 'GAR-1');

    $export = ExportSiteSpecAction::run($site, true);
    $about = collect($export->spec->pages)->firstWhere('slug', 'about');

    expect($about?->properties)->toBe(['garage.details.sku' => 'GAR-1'])
        ->and(PlanSiteSpecAction::run($export->spec, $site)->hasChanges())->toBeFalse();

    $payload = $export->spec->toArray();
    $payload['pages'][1]['properties'] = ['garage.details.sku' => 'GAR-2'];
    $changed = CapellSiteSpecData::validateAndCreate($payload);
    $plan = PlanSiteSpecAction::run($changed, $site);
    $item = collect($plan->items)->firstWhere('key', 'about');

    expect($item?->changes)->toBe(['properties.garage.details.sku'])
        ->and($item?->differences[0]->before)->toBe('GAR-1')
        ->and($item?->differences[0]->after)->toBe('GAR-2')
        ->and(PagePropertyValue::query()->value('value_text'))->toBe('GAR-1');

    ApplySiteSpecToSiteAction::run($changed, $site->refresh());

    expect(PagePropertyValue::query()->count())->toBe(1)
        ->and(PagePropertyValue::query()->value('value_text'))->toBe('GAR-2')
        ->and(PlanSiteSpecAction::run($changed, $site->refresh())->hasChanges())->toBeFalse();
});

it('names a property it cannot write as one plain value instead of exporting it wrongly', function (): void {
    $site = partsSite();
    partsProperty($site, 'tags', PropertyType::Text, 'a', multiple: true);

    $export = ExportSiteSpecAction::run($site, true);

    expect(collect($export->spec->pages)->firstWhere('slug', 'about')?->properties)->toBe([])
        ->and($export->omittedParts[SiteSpecExportData::OMITTED_PROPERTIES])->toBe(['About: garage.details.tags']);
});

it('exports media as downloadable addresses and applying the export back keeps the existing image', function (): void {
    $site = partsSite();
    $about = partsPage($site, 'about');
    $original = $about->addMediaFromString(partsPng())->usingFileName('shop.png')->toMediaCollection('image');
    Http::fake(['https://93.184.216.34/*' => Http::response(partsPng(), 200, ['Content-Type' => 'image/png'])]);

    $export = ExportSiteSpecAction::run($site, true);

    expect($export->spec->media->sourceUrl)->toBe('https://93.184.216.34')
        ->and($export->spec->media->images)->toBe(['about' => $original->refresh()->getFullUrl()]);

    $payload = $export->spec->toArray();
    $payload['pages'][1]['sections'][0]['content'] = '<p>About, reworded.</p>';
    ApplySiteSpecToSiteAction::run(CapellSiteSpecData::validateAndCreate($payload), $site->refresh());

    $images = partsPage($site, 'about')->media()->where('collection_name', 'image')->get();

    expect($images)->toHaveCount(1)
        ->and($images->first()?->getKey())->toBe($original->getKey());
});

it('leaves out an image an import could not download and says why', function (): void {
    Storage::fake('public', ['url' => 'http://garage.test/storage']);
    $site = partsSite();
    partsPage($site, 'about')->addMediaFromString(partsPng())->usingFileName('shop.png')->toMediaCollection('image');

    $export = ExportSiteSpecAction::run($site, true);

    expect($export->spec->media->hasRemoteAssets())->toBeFalse()
        ->and($export->omittedParts[SiteSpecExportData::OMITTED_MEDIA])->toHaveCount(1)
        ->and($export->omittedParts[SiteSpecExportData::OMITTED_MEDIA][0])->toContain('about', 'HTTPS');
});

it('writes the parts that registered exporters contribute, in key order, and reports what they leave out', function (): void {
    $site = partsSite();
    $exporter = static fn (string $key, string $label): SiteSpecExporter => new readonly class($key, $label) implements SiteSpecExporter
    {
        public function __construct(private string $key, private string $label) {}

        public function key(): string
        {
            return $this->key;
        }

        public function export(Site $site, array $pageSlugsById): SiteSpecExportPartData
        {
            return new SiteSpecExportPartData(
                navigations: [new CapellSiteSpecNavigationData($this->label, array_values($pageSlugsById))],
                omitted: ['custom link in ' . $this->label],
            );
        }
    };
    $registry = resolve(SiteSpecExporterRegistry::class);
    $registry->register($exporter('zeta', 'footer'));
    $registry->register($exporter('alpha', 'main'));

    $export = ExportSiteSpecAction::run($site, true);

    expect(array_column($export->spec->navigations, 'key'))->toBe(['main', 'footer'])
        ->and($export->spec->navigations[0]->pageSlugs)->toBe(['home', 'about'])
        ->and($export->omittedParts)->toBe(['alpha' => ['custom link in main'], 'zeta' => ['custom link in footer']])
        ->and(fn () => $registry->register($exporter('alpha', 'again')))->toThrow(LogicException::class, 'already registered');
});
