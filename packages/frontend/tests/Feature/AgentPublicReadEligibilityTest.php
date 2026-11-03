<?php

declare(strict_types=1);

use Capell\Core\Actions\Agent\BrowsePublicSiteMapAction;
use Capell\Core\Actions\PublicRead\PageUrlIsPubliclyReadableAction;
use Capell\Core\Actions\PublicRead\RecordPublicReadEligibilityChangeAction;
use Capell\Core\Contracts\Agent\AgentPageSearch;
use Capell\Core\Contracts\PublicRead\EvaluatesPublicPageReadCandidatesIndividually;
use Capell\Core\Contracts\PublicRead\PublicPageReadEligibility;
use Capell\Core\Contracts\PublicRead\SchedulesPublicReadTransitions;
use Capell\Core\Data\Agent\AgentSearchResultData;
use Capell\Core\Data\PublicRead\PublicPageReadCandidateData;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Capell\Core\Support\PublicRead\PublicPageReadEligibilityRegistry;
use Capell\Core\Support\PublicRead\PublicReadEligibilityRevision;
use Capell\Core\Support\PublicRead\PublicReadEligibilityState;
use Capell\Core\Support\TableName;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/** @param list<string> $restrictedUrls */
function restrictAgentReadsTo(array $restrictedUrls): void
{
    resolve(PublicPageReadEligibilityRegistry::class)->register(new readonly class($restrictedUrls) implements PublicPageReadEligibility
    {
        use EvaluatesPublicPageReadCandidatesIndividually;

        /** @param list<string> $restrictedUrls */
        public function __construct(private array $restrictedUrls) {}

        public function key(): string
        {
            return 'test-restriction';
        }

        public function isPubliclyReadable(PublicPageReadCandidateData $candidate): bool
        {
            return ! in_array($candidate->url, $this->restrictedUrls, true);
        }
    });
}

function agentPageUrl(Page $page): PageUrl
{
    return PageUrl::query()->where('pageable_id', $page->id)->where('pageable_type', $page->getMorphClass())->orderBy('id')->firstOrFail();
}

/** @return array{0: Page, 1: Page, 2: PropertySet} */
function agentPropertyPages(SiteDomain $domain): array
{
    $restricted = Page::factory()->site($domain->site)->withTranslations($domain->language, data: ['title' => 'Members-only handbook'])->published()->create();
    $public = Page::factory()->site($domain->site)->withTranslations($domain->language, data: ['title' => 'Public catalogue item'])->published()->create();
    $set = PropertySet::factory()->create(['key' => 'catalogue.details']);
    foreach (array_unique([$restricted->blueprint_id, $public->blueprint_id]) as $blueprintId) {
        BlueprintPropertySet::factory()->create(['blueprint_id' => $blueprintId, 'property_set_id' => $set->id]);
    }

    $colour = PropertyDefinition::factory()->create([
        'property_set_id' => $set->id, 'key' => 'colour', 'type' => PropertyType::Text, 'semantic' => null, 'agent_visible' => true,
    ]);
    PagePropertyValue::factory()->create([
        'page_id' => $restricted->id, 'site_id' => $domain->site_id, 'property_definition_id' => $colour->id, 'value_text' => 'Members-only colour',
    ]);
    PagePropertyValue::factory()->create([
        'page_id' => $public->id, 'site_id' => $domain->site_id, 'property_definition_id' => $colour->id, 'value_text' => 'Green',
    ]);

    return [$restricted, $public, $set];
}

it('omits a restricted page from agent navigation before pagination', function (): void {
    $domain = SiteDomain::factory()->default()->create();
    $restricted = Page::factory()->site($domain->site)->create(['visible_from' => now()->subDay()]);
    Translation::factory()->translatable($restricted)->language($domain->language)->create(['title' => 'Members-only handbook']);
    foreach (range(1, 50) as $index) {
        $public = Page::factory()->site($domain->site)->create(['visible_from' => now()->subDay()]);
        Translation::factory()->translatable($public)->language($domain->language)->create(['title' => sprintf('Public page %02d', $index)]);
    }

    $restrictedUrl = agentPageUrl($restricted);
    // Sort the restricted address first so an unfiltered first page would contain it.
    $restrictedUrl->forceFill(['url' => '/0-members/handbook'])->save();
    restrictAgentReadsTo([$restrictedUrl->refresh()->fullUrl()]);

    $response = $this->getJson($domain->full_url . '/agent/v1/navigation')->assertOk()
        ->assertJsonCount(50, 'data')
        ->assertJsonPath('links.next', null)
        ->assertDontSee('Members-only handbook')
        ->assertDontSee('0-members');

    expect(collect($response->json('data'))->pluck('title')->all())->toContain('Public page 01', 'Public page 50');
});

it('omits a restricted page and its properties from the agent pages endpoint before pagination', function (): void {
    $domain = SiteDomain::factory()->default()->create();
    [$restricted, , $set] = agentPropertyPages($domain);
    $restrictedUrl = agentPageUrl($restricted)->fullUrl();
    restrictAgentReadsTo([$restrictedUrl]);

    $this->getJson($domain->full_url . '/agent/v1/pages?set=' . $set->key . '&page[size]=1')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Public catalogue item')
        ->assertJsonPath('data.0.properties', ['capell:catalogue.details.colour' => 'Green'])
        ->assertJsonPath('links.next', null)
        ->assertDontSee('Members-only handbook')
        ->assertDontSee('Members-only colour')
        ->assertDontSee($restrictedUrl, escape: false);
});

it('omits a restricted page from agent search results', function (): void {
    $domain = SiteDomain::factory()->default()->create();
    $search = new class implements AgentPageSearch
    {
        public function search(string $query, int $siteId, ?int $languageId, int $page = 1): LengthAwarePaginatorContract
        {
            return new LengthAwarePaginator([
                new AgentSearchResultData('https://agent.test/members/handbook', 'Members-only handbook', 'Members-only snippet'),
                new AgentSearchResultData('https://agent.test/catalogue', 'Public catalogue item', 'Public snippet'),
            ], 2, 10, $page);
        }
    };
    app()->instance(AgentPageSearch::class, $search);
    restrictAgentReadsTo(['https://agent.test/members/handbook']);

    $this->getJson($domain->full_url . '/agent/v1/search?q=handbook')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Public catalogue item')
        ->assertDontSee('Members-only handbook')
        ->assertDontSee('Members-only snippet')
        ->assertDontSee('members/handbook', escape: false);
});

it('omits a reference to a restricted page from a public page projection', function (): void {
    $domain = SiteDomain::factory()->default()->create();
    [$restricted, $public, $set] = agentPropertyPages($domain);
    $related = PropertyDefinition::factory()->create([
        'property_set_id' => $set->id, 'key' => 'related', 'type' => PropertyType::EntryReference, 'semantic' => null, 'agent_visible' => true,
    ]);
    PagePropertyValue::factory()->create([
        'page_id' => $public->id, 'site_id' => $domain->site_id, 'property_definition_id' => $related->id, 'referenced_page_id' => $restricted->id,
    ]);
    $restrictedUrl = agentPageUrl($restricted)->fullUrl();
    restrictAgentReadsTo([$restrictedUrl]);

    $this->getJson($domain->full_url . '/agent/v1/pages?set=' . $set->key)->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Public catalogue item')
        ->assertJsonPath('data.0.properties', ['capell:catalogue.details.colour' => 'Green'])
        ->assertDontSee('Members-only handbook')
        ->assertDontSee($restrictedUrl, escape: false);
});

it('lists every page when no package restricts public reads', function (): void {
    $domain = SiteDomain::factory()->default()->create();
    [, , $set] = agentPropertyPages($domain);

    $this->getJson($domain->full_url . '/agent/v1/pages?set=' . $set->key)->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertSee('Members-only handbook')
        ->assertSee('Public catalogue item');
});

it('denies a page whose eligibility contributor fails', function (): void {
    $registry = resolve(PublicPageReadEligibilityRegistry::class);
    $candidate = new PublicPageReadCandidateData('https://agent.test/page', siteId: 1, languageId: 1);

    expect($registry->allows($candidate))->toBeTrue();

    $registry->register(new class implements PublicPageReadEligibility
    {
        use EvaluatesPublicPageReadCandidatesIndividually;

        public function key(): string
        {
            return 'failing';
        }

        public function isPubliclyReadable(PublicPageReadCandidateData $candidate): bool
        {
            throw new RuntimeException('Access rules unavailable.');
        }
    });

    expect($registry->allows($candidate))->toBeFalse()
        ->and($registry->allows(new PublicPageReadCandidateData('')))->toBeFalse();
});

it('denies a served address whose public URL cannot be built once a package restricts reads', function (): void {
    $domain = SiteDomain::factory()->default()->create();
    $page = Page::factory()->site($domain->site)->withTranslations($domain->language, data: ['title' => 'Orphaned address'])->published()->create();
    $url = agentPageUrl($page);

    expect(PageUrlIsPubliclyReadableAction::run($url))->toBeTrue();

    restrictAgentReadsTo([]);
    $url->setRelation('siteDomain', null);

    expect(PageUrlIsPubliclyReadableAction::run($url))->toBeFalse()
        ->and(PageUrlIsPubliclyReadableAction::run(agentPageUrl($page)))->toBeTrue();
});

/**
 * A contributor that records every batch it is asked about, and restricts the
 * listed URLs (or, with a prefix, every URL that starts with it).
 *
 * @param  list<string>  $restrictedUrls
 */
function recordingAgentReadEligibility(array $restrictedUrls = [], ?string $restrictedPrefix = null): PublicPageReadEligibility
{
    return new class($restrictedUrls, $restrictedPrefix) implements PublicPageReadEligibility
    {
        /** @var list<list<string>> */
        public array $batches = [];

        /** @param list<string> $restrictedUrls */
        public function __construct(private readonly array $restrictedUrls, private readonly ?string $restrictedPrefix) {}

        public function key(): string
        {
            return 'test-recording';
        }

        public function isPubliclyReadable(PublicPageReadCandidateData $candidate): bool
        {
            return $this->arePubliclyReadable([$candidate])[0];
        }

        public function arePubliclyReadable(array $candidates): array
        {
            $this->batches[] = array_map(static fn (PublicPageReadCandidateData $candidate): string => $candidate->url, $candidates);

            return array_map(fn (PublicPageReadCandidateData $candidate): bool => ! in_array($candidate->url, $this->restrictedUrls, true)
                && ($this->restrictedPrefix === null || ! str_starts_with($candidate->url, $this->restrictedPrefix)), $candidates);
        }
    };
}

/**
 * @return list<string>
 */
function recordedAgentReadBatchUrls(PublicPageReadEligibility $eligibility, int $from = 0): array
{
    $batches = property_exists($eligibility, 'batches') && is_array($eligibility->batches) ? $eligibility->batches : [];

    return array_values(array_map(static fn (mixed $batch): string => implode("\n", is_array($batch) ? array_filter($batch, is_string(...)) : []), array_slice($batches, $from)));
}

it('pages agent navigation past a restricted page with a bounded batched scan', function (): void {
    $domain = SiteDomain::factory()->default()->create();
    $restricted = Page::factory()->site($domain->site)->withTranslations($domain->language, data: ['title' => 'Members-only handbook'])->published()->create();
    foreach (range(1, 260) as $index) {
        Page::factory()->site($domain->site)->withTranslations($domain->language, data: ['title' => sprintf('Public page %03d', $index)])->published()->create();
    }

    $restrictedUrl = agentPageUrl($restricted);
    $restrictedUrl->forceFill(['url' => '/0-members/handbook'])->save();
    $eligibility = recordingAgentReadEligibility([$restrictedUrl->refresh()->fullUrl()]);
    resolve(PublicPageReadEligibilityRegistry::class)->register($eligibility);

    // Drain the work that page creation deferred to the end of a request.
    $this->getJson($domain->full_url . '/agent/v1/navigation')->assertOk();
    $earlierBatches = count(recordedAgentReadBatchUrls($eligibility));

    DB::enableQueryLog();
    DB::flushQueryLog();
    $first = BrowsePublicSiteMapAction::run($domain->site, $domain->language);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // One contributor call over one scan chunk answers page one: the rest of the site is never read.
    expect($first->items())->toHaveCount(50)
        ->and(array_column($first->items(), 'title'))->not->toContain('Members-only handbook')
        ->and($first->hasMorePages())->toBeTrue()
        ->and(recordedAgentReadBatchUrls($eligibility, $earlierBatches))->toHaveCount(1)
        ->and(substr_count(recordedAgentReadBatchUrls($eligibility, $earlierBatches)[0] ?? '', "\n") + 1)->toBeLessThanOrEqual(200)
        ->and($queries)->toBeLessThan(15);

    // Following the next links (cursors) reaches every public page exactly once.
    $titles = [];
    $url = $domain->full_url . '/agent/v1/navigation';
    $requests = 0;
    while (is_string($url) && $requests++ < 10) {
        $response = $this->getJson($url)->assertOk()->assertDontSee('Members-only handbook')->assertJsonMissingPath('meta.total');
        $data = $response->json('data');
        foreach (is_array($data) ? $data : [] as $entry) {
            $titles[] = is_array($entry) && is_string($entry['title'] ?? null) ? $entry['title'] : '';
        }

        $next = $response->json('links.next');
        expect($next === null || str_contains((string) $next, 'cursor='))->toBeTrue();
        $url = is_string($next) ? $next : null;
    }

    expect($requests)->toBe(6)
        ->and($titles)->toHaveCount(260)
        ->and(array_unique($titles))->toHaveCount(260);
});

it('resolves a relative candidate only against the domain it is published on', function (): void {
    $domain = SiteDomain::factory()->default()->create();
    $eligibility = recordingAgentReadEligibility();
    $registry = resolve(PublicPageReadEligibilityRegistry::class)->register($eligibility);

    $decisions = $registry->allowsMany([
        new PublicPageReadCandidateData('/catalogue', siteId: $domain->site_id, languageId: $domain->language_id, publishedOn: 'https://published.test/fr'),
        new PublicPageReadCandidateData('/catalogue', siteId: $domain->site_id, languageId: $domain->language_id),
        new PublicPageReadCandidateData('//elsewhere.test/catalogue', publishedOn: 'https://published.test'),
        new PublicPageReadCandidateData('https://agent.test/catalogue', siteId: $domain->site_id),
    ]);

    // Without its publishing domain a relative address is never guessed onto another domain.
    expect($decisions)->toBe([true, false, false, true])
        ->and(recordedAgentReadBatchUrls($eligibility))->toBe(["https://published.test/fr/catalogue\nhttps://agent.test/catalogue"]);
});

it('denies the whole batch when a contributor answers for the wrong number of candidates', function (): void {
    $registry = resolve(PublicPageReadEligibilityRegistry::class)->register(new class implements PublicPageReadEligibility
    {
        public function key(): string
        {
            return 'short-answer';
        }

        public function isPubliclyReadable(PublicPageReadCandidateData $candidate): bool
        {
            return true;
        }

        public function arePubliclyReadable(array $candidates): array
        {
            return [true];
        }
    });

    expect($registry->allowsMany([
        new PublicPageReadCandidateData('https://agent.test/a'),
        new PublicPageReadCandidateData('https://agent.test/b'),
    ]))->toBe([false, false]);
});

it('keeps the eligibility revision in the database, across cache clears', function (): void {
    restrictAgentReadsTo([]);
    $revision = resolve(PublicReadEligibilityRevision::class);
    $before = $revision->current();

    Cache::flush();

    expect($before)->toBeString()
        ->and($revision->fresh())->toBe($before)
        ->and(RecordPublicReadEligibilityChangeAction::run('test'))->not->toBe($before)
        ->and(resolve(PublicReadEligibilityState::class)->isCurrent($before, null))->toBeFalse();
});

it('denies every read and stores nothing when the revision cannot be read', function (): void {
    $domain = SiteDomain::factory()->default()->create();
    Page::factory()->site($domain->site)->withTranslations($domain->language, data: ['title' => 'Public catalogue item'])->published()->create();
    restrictAgentReadsTo([]);
    Schema::drop(TableName::resolve('public_read_eligibility_state'));
    app()->forgetScopedInstances();

    expect(resolve(PublicPageReadEligibilityRegistry::class)->allows(new PublicPageReadCandidateData('https://agent.test/page')))->toBeFalse()
        ->and(resolve(PublicReadEligibilityState::class)->stamp())->toBeNull()
        ->and(resolve(PublicReadEligibilityState::class)->isCurrent('anything', null))->toBeFalse();

    $this->getJson($domain->full_url . '/agent/v1/navigation')->assertOk()->assertDontSee('Public catalogue item');
});

it('treats a stored projection as stale once a scheduled transition is reached', function (): void {
    resolve(PublicPageReadEligibilityRegistry::class)->register(new class implements PublicPageReadEligibility, SchedulesPublicReadTransitions
    {
        use EvaluatesPublicPageReadCandidatesIndividually;

        public function key(): string
        {
            return 'test-scheduled';
        }

        public function isPubliclyReadable(PublicPageReadCandidateData $candidate): bool
        {
            return true;
        }

        public function nextPublicReadTransitionAfter(CarbonImmutable $now): ?CarbonImmutable
        {
            $opening = CarbonImmutable::parse('2026-11-03 12:00:00');

            return $now->lessThan($opening) ? $opening : null;
        }
    });
    $this->travelTo(CarbonImmutable::parse('2026-11-03 11:00:00'));
    $state = resolve(PublicReadEligibilityState::class);
    $stamp = $state->stamp();

    expect($stamp?->validUntil?->toDateTimeString())->toBe('2026-11-03 12:00:00')
        ->and($state->isCurrent($stamp?->revision, $stamp?->validUntilTimestamp()))->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00'));
    app()->forgetScopedInstances();
    $after = resolve(PublicReadEligibilityState::class);

    expect($after->isCurrent($stamp?->revision, $stamp?->validUntilTimestamp()))->toBeFalse()
        ->and($after->stamp()?->key())->not->toBe($stamp?->key());
});

it('builds agent search links from readable results only', function (): void {
    $domain = SiteDomain::factory()->default()->create();
    app()->instance(AgentPageSearch::class, new class implements AgentPageSearch
    {
        public function search(string $query, int $siteId, ?int $languageId, int $page = 1): LengthAwarePaginatorContract
        {
            $results = [new AgentSearchResultData('https://agent.test/members/handbook', 'Members-only handbook', 'Members-only snippet')];
            foreach (range(1, 11) as $index) {
                $results[] = new AgentSearchResultData(sprintf('https://agent.test/catalogue/%02d', $index), sprintf('Catalogue %02d', $index), 'Snippet');
            }

            return new LengthAwarePaginator(array_slice($results, ($page - 1) * 5, 5), 12, 5, $page)->withPath('https://provider.test/unfiltered');
        }
    });
    restrictAgentReadsTo(['https://agent.test/members/handbook']);

    $titles = [];
    $url = $domain->full_url . '/agent/v1/search?q=catalogue';
    $requests = 0;
    while (is_string($url) && $requests++ < 6) {
        $response = $this->getJson($url)->assertOk()->assertDontSee('Members-only')->assertDontSee('provider.test');
        $data = $response->json('data');
        foreach (is_array($data) ? $data : [] as $entry) {
            $titles[] = is_array($entry) && is_string($entry['title'] ?? null) ? $entry['title'] : '';
        }

        $next = $response->json('links.next');
        $url = is_string($next) ? $next : null;
    }

    expect($requests)->toBe(3)
        ->and($titles)->toHaveCount(11)
        ->and(array_unique($titles))->toHaveCount(11);
});

it('removes exported static pages when public-read eligibility changes', function (?bool $scopedToSite): void {
    $domain = SiteDomain::factory()->default()->create();
    $root = sys_get_temp_dir() . '/capell-static-eligibility-' . bin2hex(random_bytes(4));
    config(['capell-frontend.static_artifacts_path' => $root]);
    $store = resolve(StaticPageArtifactStore::class);
    $store->putOwnedHtml('directory/index.html', 'Links to a members-only page', $domain->site_id);

    try {
        expect(is_file($root . '/directory/index.html'))->toBeTrue();

        RecordPublicReadEligibilityChangeAction::run('test', $scopedToSite === true ? $domain->site_id : null);

        expect(is_file($root . '/directory/index.html'))->toBeFalse();
    } finally {
        File::deleteDirectory($root);
    }
})->with(['for its site' => true, 'for every site' => null]);

it('pages unrestricted agent navigation and page reads with no gap or repeat at page boundaries', function (): void {
    $domain = SiteDomain::factory()->default()->create();
    [, $public, $set] = agentPropertyPages($domain);
    $colour = PropertyDefinition::query()->where('property_set_id', $set->id)->where('key', 'colour')->firstOrFail();
    foreach (range(1, 118) as $index) {
        $page = Page::factory()->site($domain->site)->withTranslations($domain->language, data: ['title' => sprintf('Public page %03d', $index)])->published()->create(['blueprint_id' => $public->blueprint_id]);
        PagePropertyValue::factory()->create([
            'page_id' => $page->id, 'site_id' => $domain->site_id, 'property_definition_id' => $colour->id, 'value_text' => sprintf('Colour %03d', $index),
        ]);
    }

    $navigation = [];
    foreach (range(1, 3) as $number) {
        $navigation = [...$navigation, ...BrowsePublicSiteMapAction::run($domain->site, $domain->language, $number)->items()];
    }

    $urls = array_column($navigation, 'url');
    $expected = PageUrl::query()->where('site_id', $domain->site_id)->count();

    expect($urls)->toHaveCount(min($expected, 150))
        ->and(array_unique($urls))->toHaveCount(count($urls));

    $titles = [];
    foreach (range(1, 3) as $number) {
        $response = $this->getJson($domain->full_url . '/agent/v1/pages?set=' . $set->key . '&page[size]=50&page[number]=' . $number)->assertOk();
        foreach ((array) $response->json('data') as $entry) {
            $titles[] = is_array($entry) ? json_encode($entry) : '';
        }
    }

    expect($titles)->toHaveCount(120)
        ->and(array_unique($titles))->toHaveCount(120);
});
