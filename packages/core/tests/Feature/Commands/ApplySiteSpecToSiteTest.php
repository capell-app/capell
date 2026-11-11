<?php

declare(strict_types=1);

use Capell\Core\Actions\ApplySiteSpecToSiteAction;
use Capell\Core\Actions\BuildCapellSiteFromSpecAction;
use Capell\Core\Actions\CreateDefaultLanguagesAction;
use Capell\Core\Actions\PlanSiteSpecAction;
use Capell\Core\Contracts\SiteSpec\SiteSpecApplier;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Enums\SiteSpecPlanAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Publishing\PublishSentinel;
use Capell\Core\Support\SiteSpec\SiteSpecApplierRegistry;
use Capell\Core\Support\SiteSpec\SiteSpecHasher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Command\Command;

beforeEach(function (): void {
    CreateDefaultLanguagesAction::run(['en']);
    Queue::fake();
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');
    config()->set('media-library.queue_conversions_by_default', true);
});

function applyPng(): string
{
    $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z3h8AAAAASUVORK5CYII=', true);

    throw_unless(is_string($image), RuntimeException::class, 'Unable to decode the PNG fixture.');

    return $image;
}

/** Registers a navigation applier that counts how often it runs. */
function countingNavigationApplier(): object
{
    $applier = new class implements SiteSpecApplier
    {
        public int $runs = 0;

        public function key(): string
        {
            return 'navigation';
        }

        /** @param array<string, Page> $pagesBySlug */
        public function apply(CapellSiteSpecData $spec, Site $site, array $pagesBySlug): void
        {
            $this->runs++;
        }
    };
    resolve(SiteSpecApplierRegistry::class)->register($applier);

    return $applier;
}

/**
 * @param  list<array{slug: string, name: string, title: string, content: string}>  $pages
 * @return array<string, mixed>
 */
function applySpecPayload(array $pages, string $visibility = 'private'): array
{
    return [
        'site' => ['name' => 'Harbour Books'],
        'theme' => ['key' => 'default', 'colors' => ['primary' => '#123456']],
        'pages' => array_map(fn (array $page, int $index): array => [
            'name' => $page['name'],
            'slug' => $page['slug'],
            'title' => $page['title'],
            'pageType' => 'default',
            'order' => $index,
            'sections' => [['type' => 'content', 'content' => $page['content']]],
        ], $pages, array_keys($pages)),
        'initialVisibility' => $visibility,
        'acknowledgePublic' => $visibility === 'public',
    ];
}

function applySpec(array $payload): CapellSiteSpecData
{
    return CapellSiteSpecData::validateAndCreate($payload);
}

function applyPage(Site $site, string $slug): Page
{
    return $site->pages()->with('translations')->get()
        ->first(fn (Page $page): bool => ($page->translations->first()?->meta['slug'] ?? null) === $slug) ?? throw new RuntimeException('Missing page ' . $slug);
}

/** @return list<array<string, string>> */
function basePages(): array
{
    return [
        ['slug' => 'home', 'name' => 'Home', 'title' => 'Welcome', 'content' => '<p>Home copy.</p>'],
        ['slug' => 'about', 'name' => 'About', 'title' => 'About us', 'content' => '<p>About copy.</p>'],
    ];
}

it('plans every page as a create when there is no site yet, writing nothing', function (): void {
    $siteCount = Site::query()->count();

    $plan = PlanSiteSpecAction::run(applySpec(applySpecPayload(basePages())));

    expect($plan->count(SiteSpecPlanAction::Create))->toBe(2)
        ->and($plan->hasChanges())->toBeTrue()
        ->and(Site::query()->count())->toBe($siteCount);
});

it('plans creates, updates and skips against an existing site and writes nothing', function (): void {
    $site = BuildCapellSiteFromSpecAction::run(applySpec(applySpecPayload(basePages())));
    $about = applyPage($site, 'about');
    $about->translations->first()?->update(['content' => '<p>Edited.</p>']);

    $changed = basePages();
    $changed[] = ['slug' => 'contact', 'name' => 'Contact', 'title' => 'Contact us', 'content' => '<p>Write to us.</p>'];
    $pageCount = $site->pages()->count();

    $plan = PlanSiteSpecAction::run(applySpec(applySpecPayload($changed)), $site->refresh());
    $byKey = collect($plan->items)->where('kind', 'page')->keyBy('key');

    expect($byKey['contact']->action)->toBe(SiteSpecPlanAction::Create)
        ->and($byKey['about']->action)->toBe(SiteSpecPlanAction::Update)
        ->and($byKey['about']->changes)->toBe(['content'])
        ->and($site->pages()->count())->toBe($pageCount)
        ->and(applyPage($site, 'about')->translations->first()?->content)->toBe('<p>Edited.</p>');
});

it('applies a changed spec to the existing site instead of creating another one', function (): void {
    $draft = basePages();
    $site = BuildCapellSiteFromSpecAction::run(applySpec(applySpecPayload($draft)));
    // Pages a private import creates after the homepage start as drafts.
    $siteCount = Site::query()->count();

    $changed = basePages();
    $changed[1]['title'] = 'About the shop';
    $changed[1]['content'] = '<p>Updated about copy.</p>';
    $changed[] = ['slug' => 'contact', 'name' => 'Contact', 'title' => 'Contact us', 'content' => '<p>Write to us.</p>'];
    $spec = applySpec(applySpecPayload($changed));

    $plan = ApplySiteSpecToSiteAction::run($spec, $site->refresh());
    $site->refresh();

    expect(Site::query()->count())->toBe($siteCount)
        ->and($plan->count(SiteSpecPlanAction::Create))->toBe(1)
        ->and(applyPage($site, 'about')->translations->first()?->title)->toBe('About the shop')
        ->and(applyPage($site, 'about')->translations->first()?->content)->toContain('Updated about copy.')
        ->and(applyPage($site, 'contact')->name)->toBe('Contact')
        ->and($site->meta['site_spec_applied_hash'])->toBe(SiteSpecHasher::hash($spec));
});

it('applies the same spec twice without changing anything the second time', function (): void {
    $site = BuildCapellSiteFromSpecAction::run(applySpec(applySpecPayload(basePages())));
    $changed = basePages();
    $changed[1]['content'] = '<p>Second version.</p>';
    $spec = applySpec(applySpecPayload($changed));

    ApplySiteSpecToSiteAction::run($spec, $site->refresh());
    $second = ApplySiteSpecToSiteAction::run($spec, $site->refresh());

    expect($second->hasChanges())->toBeFalse()
        ->and($site->pages()->count())->toBe(2);
});

it('never lets a private spec change a page that is already live', function (): void {
    $site = BuildCapellSiteFromSpecAction::run(applySpec(applySpecPayload(basePages())));
    // The homepage of a private import is published with a coming-soon placeholder.
    $home = applyPage($site, 'home');
    $placeholder = $home->translations->first()?->content;

    $plan = ApplySiteSpecToSiteAction::run(applySpec(applySpecPayload(basePages())), $site->refresh());
    $homeItem = collect($plan->items)->firstWhere('key', 'home');

    expect($homeItem?->action)->toBe(SiteSpecPlanAction::Skip)
        ->and($homeItem?->note)->toBe('live_page_held')
        ->and(applyPage($site, 'home')->translations->first()?->content)->toBe($placeholder)
        ->and(PublishSentinel::isDraftValue(applyPage($site, 'home')->visible_from))->toBeFalse();
});

it('creates pages a private spec adds as drafts', function (): void {
    $site = BuildCapellSiteFromSpecAction::run(applySpec(applySpecPayload(basePages())));
    $changed = basePages();
    $changed[] = ['slug' => 'contact', 'name' => 'Contact', 'title' => 'Contact us', 'content' => '<p>Write to us.</p>'];

    ApplySiteSpecToSiteAction::run(applySpec(applySpecPayload($changed)), $site->refresh());

    expect(PublishSentinel::isDraftValue(applyPage($site->refresh(), 'contact')->visible_from))->toBeTrue();
});

it('updates a live page when the spec is public and acknowledged', function (): void {
    $site = BuildCapellSiteFromSpecAction::run(applySpec(applySpecPayload(basePages(), 'public')));
    $changed = basePages();
    $changed[0]['content'] = '<p>New home copy.</p>';

    ApplySiteSpecToSiteAction::run(applySpec(applySpecPayload($changed, 'public')), $site->refresh());

    expect(applyPage($site->refresh(), 'home')->translations->first()?->content)->toContain('New home copy.');
});

it('refuses a spec written in a language the site does not have', function (): void {
    $site = BuildCapellSiteFromSpecAction::run(applySpec(applySpecPayload(basePages())));
    CreateDefaultLanguagesAction::run(['fr']);
    $payload = applySpecPayload(basePages());
    $payload['language'] = ['code' => 'fr', 'name' => 'French', 'locale' => 'fr_FR', 'flag' => 'fr', 'default' => false];

    expect(fn (): mixed => ApplySiteSpecToSiteAction::run(applySpec($payload), $site->refresh()))->toThrow(RuntimeException::class, 'does not have the language');
});

it('prints the plan from the import command with --plan and writes nothing', function (): void {
    $site = BuildCapellSiteFromSpecAction::run(applySpec(applySpecPayload(basePages())));
    $changed = basePages();
    $changed[1]['content'] = '<p>Plan only.</p>';
    $changed[] = ['slug' => 'contact', 'name' => 'Contact', 'title' => 'Contact us', 'content' => '<p>Write to us.</p>'];
    $path = tempnam(sys_get_temp_dir(), 'capell-site-spec-plan-');
    file_put_contents($path, json_encode(applySpecPayload($changed), JSON_THROW_ON_ERROR));
    $pageCount = $site->pages()->count();

    try {
        $command = $this->artisan('capell:site-spec-import', ['spec' => $path, '--plan' => true, '--site' => (string) $site->getKey()]);
        assert($command instanceof PendingCommand);
        $command->expectsOutputToContain('Nothing was written')->assertExitCode(Command::SUCCESS)->run();

        expect($site->pages()->count())->toBe($pageCount);
    } finally {
        unlink($path);
    }
});

it('applies a changed spec to a named site from the import command', function (): void {
    $site = BuildCapellSiteFromSpecAction::run(applySpec(applySpecPayload(basePages())));
    $changed = basePages();
    $changed[] = ['slug' => 'contact', 'name' => 'Contact', 'title' => 'Contact us', 'content' => '<p>Write to us.</p>'];
    $path = tempnam(sys_get_temp_dir(), 'capell-site-spec-apply-');
    file_put_contents($path, json_encode(applySpecPayload($changed), JSON_THROW_ON_ERROR));

    try {
        $command = $this->artisan('capell:site-spec-import', ['spec' => $path, '--site' => (string) $site->getKey()]);
        assert($command instanceof PendingCommand);
        $command->expectsOutputToContain('1 page(s) created')->assertExitCode(Command::SUCCESS)->run();

        expect($site->pages()->count())->toBe(3);
    } finally {
        unlink($path);
    }
});

it('reports an unknown site and refuses mixing the plan options with repair', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-site-spec-bad-');
    file_put_contents($path, json_encode(applySpecPayload(basePages()), JSON_THROW_ON_ERROR));

    try {
        $unknown = $this->artisan('capell:site-spec-import', ['spec' => $path, '--site' => '999999']);
        assert($unknown instanceof PendingCommand);
        $unknown->expectsOutputToContain('No site with the ID 999999')->assertExitCode(Command::FAILURE)->run();

        $mixed = $this->artisan('capell:site-spec-import', ['spec' => $path, '--plan' => true, '--repair-private' => true]);
        assert($mixed instanceof PendingCommand);
        $mixed->expectsOutputToContain('on their own')->assertExitCode(Command::FAILURE)->run();
    } finally {
        unlink($path);
    }
});

it('finds a page by the slug its spec stores it under, so a changed spec never duplicates it', function (): void {
    $payload = applySpecPayload(basePages());
    $payload['pages'][1]['meta'] = ['slug' => 'our-story'];
    $site = BuildCapellSiteFromSpecAction::run(applySpec($payload));

    $payload['pages'][1]['sections'][0]['content'] = '<p>Reworded.</p>';
    ApplySiteSpecToSiteAction::run(applySpec($payload), $site->refresh());

    expect($site->pages()->count())->toBe(2)
        ->and(applyPage($site, 'our-story')->translations->first()?->content)->toContain('Reworded.');
});

it('keeps the original import hash and records the applied hash beside it', function (): void {
    $original = applySpec(applySpecPayload(basePages()));
    $site = BuildCapellSiteFromSpecAction::run($original);
    $changed = basePages();
    $changed[] = ['slug' => 'contact', 'name' => 'Contact', 'title' => 'Contact us', 'content' => '<p>Write to us.</p>'];
    $second = applySpec(applySpecPayload($changed));

    ApplySiteSpecToSiteAction::run($second, $site->refresh());
    $site->refresh();

    expect($site->meta['site_spec_hash'])->toBe(SiteSpecHasher::hash($original))
        ->and($site->meta['site_spec_applied_hash'])->toBe(SiteSpecHasher::hash($second))
        ->and(BuildCapellSiteFromSpecAction::run($original)->getKey())->toBe($site->getKey());
});

it('runs package-owned parts once per distinct spec', function (): void {
    $applier = countingNavigationApplier();
    $payload = applySpecPayload(basePages(), 'public');
    $payload['navigations'] = [['key' => 'main', 'name' => 'Main', 'pageSlugs' => ['home', 'about']]];
    $site = BuildCapellSiteFromSpecAction::run(applySpec($payload));
    $payload['pages'][1]['sections'][0]['content'] = '<p>Changed.</p>';
    $spec = applySpec($payload);

    ApplySiteSpecToSiteAction::run($spec, $site->refresh());
    ApplySiteSpecToSiteAction::run($spec, $site->refresh());

    expect($applier->runs)->toBe(2);
});

it('does not attach private media to live pages or the live site', function (): void {
    Http::fake(['https://93.184.216.34/*' => Http::response(applyPng(), 200, ['Content-Type' => 'image/png'])]);
    $site = BuildCapellSiteFromSpecAction::run(applySpec(applySpecPayload(basePages())));
    $payload = applySpecPayload(basePages());
    $payload['pages'][0]['sections'][0]['content'] = '<p>New home copy.</p>';
    $payload['media'] = ['sourceUrl' => 'https://93.184.216.34', 'logo' => 'https://93.184.216.34/logo.png', 'images' => ['home' => 'https://93.184.216.34/home.png']];

    $plan = ApplySiteSpecToSiteAction::run(applySpec($payload), $site->refresh());

    expect($plan->packagePartsHeld)->toBeTrue()
        ->and(applyPage($site, 'home')->media()->count())->toBe(0)
        ->and($site->refresh()->media()->count())->toBe(0);
});

it('attaches public media once, however many times the page changes', function (): void {
    Http::fake(['https://93.184.216.34/*' => Http::response(applyPng(), 200, ['Content-Type' => 'image/png'])]);
    $payload = applySpecPayload(basePages(), 'public');
    $payload['media'] = ['sourceUrl' => 'https://93.184.216.34', 'images' => ['home' => 'https://93.184.216.34/home.png']];
    $site = BuildCapellSiteFromSpecAction::run(applySpec($payload));
    $afterBuild = applyPage($site, 'home')->media()->count();

    $payload['pages'][0]['sections'][0]['content'] = '<p>Reworded.</p>';
    ApplySiteSpecToSiteAction::run(applySpec($payload), $site->refresh());
    $payload['pages'][0]['sections'][0]['content'] = '<p>Reworded again.</p>';
    ApplySiteSpecToSiteAction::run(applySpec($payload), $site->refresh());

    expect($afterBuild)->toBe(1)
        ->and(applyPage($site, 'home')->media()->count())->toBe(1);
});

it('plans with the same refusals as apply', function (): void {
    $site = BuildCapellSiteFromSpecAction::run(applySpec(applySpecPayload(basePages())));
    CreateDefaultLanguagesAction::run(['fr']);
    $french = applySpecPayload(basePages());
    $french['language'] = ['code' => 'fr', 'name' => 'French', 'locale' => 'fr_FR', 'flag' => 'fr', 'default' => false];
    $unacknowledged = applySpecPayload(basePages(), 'public');
    $unacknowledged['acknowledgePublic'] = false;

    expect(fn (): mixed => PlanSiteSpecAction::run(applySpec($french), $site->refresh()))->toThrow(RuntimeException::class, 'does not have the language')
        ->and(fn (): mixed => PlanSiteSpecAction::run(applySpec($unacknowledged)))->toThrow(RuntimeException::class, 'acknowledgement');
});

it('refuses to guess when two pages on the site share a slug', function (): void {
    $site = BuildCapellSiteFromSpecAction::run(applySpec(applySpecPayload(basePages())));
    $home = applyPage($site, 'home')->translations->first();
    Translation::withoutEvents(fn () => $home?->update(['meta' => array_merge($home->meta ?? [], ['slug' => 'about'])]));

    expect(fn (): mixed => PlanSiteSpecAction::run(applySpec(applySpecPayload(basePages())), $site->refresh()))
        ->toThrow(RuntimeException::class, 'More than one page');
});

it('writes the page properties of a page the spec adds to an existing site', function (): void {
    $site = BuildCapellSiteFromSpecAction::run(applySpec(applySpecPayload(basePages())));
    $blueprint = Blueprint::query()->pageType()->where('key', 'default')->firstOrFail();
    $set = PropertySet::factory()->create();
    PropertyDefinition::factory()->withConstraints(['max_length' => 8])->create([
        'property_set_id' => $set->id,
        'key' => 'sku',
        'type' => PropertyType::Text,
    ]);
    BlueprintPropertySet::factory()->create(['blueprint_id' => $blueprint->id, 'property_set_id' => $set->id]);
    $changed = applySpecPayload([...basePages(), ['slug' => 'contact', 'name' => 'Contact', 'title' => 'Contact us', 'content' => '<p>Write to us.</p>']]);
    $changed['pages'][2]['properties'] = ['sku' => 'GFT-0001'];

    ApplySiteSpecToSiteAction::run(applySpec($changed), $site->refresh());

    expect(PagePropertyValue::query()->where('page_id', applyPage($site->refresh(), 'contact')->id)->value('value_text'))->toBe('GFT-0001');

    $refused = applySpecPayload([...basePages(), ['slug' => 'faq', 'name' => 'FAQ', 'title' => 'Questions', 'content' => '<p>Ask us.</p>']]);
    $refused['pages'][2]['properties'] = ['sku' => 'FAR-TOO-LONG'];

    expect(fn (): mixed => ApplySiteSpecToSiteAction::run(applySpec($refused), $site->refresh()))
        ->toThrow(ValidationException::class, 'Sku must be 8 characters or fewer. Shorten the text.');
});
