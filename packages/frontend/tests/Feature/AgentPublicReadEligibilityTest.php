<?php

declare(strict_types=1);

use Capell\Core\Actions\Agent\BrowsePublicSiteMapAction;
use Capell\Core\Actions\PublicRead\PageUrlIsPubliclyReadableAction;
use Capell\Core\Contracts\Agent\AgentPageSearch;
use Capell\Core\Contracts\PublicRead\EvaluatesPublicPageReadCandidatesIndividually;
use Capell\Core\Contracts\PublicRead\PublicPageReadEligibility;
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
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

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
 * Records every batch it is asked about, and restricts the listed URLs.
 */
final class RecordingAgentReadEligibility implements PublicPageReadEligibility
{
    /** @var list<list<string>> */
    public array $batches = [];

    /** @param list<string> $restrictedUrls */
    public function __construct(private readonly array $restrictedUrls = []) {}

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

        return array_map(fn (PublicPageReadCandidateData $candidate): bool => ! in_array($candidate->url, $this->restrictedUrls, true), $candidates);
    }
}

it('pages agent navigation past a restricted page with a bounded batched scan', function (): void {
    $domain = SiteDomain::factory()->default()->create();
    $restricted = Page::factory()->site($domain->site)->withTranslations($domain->language, data: ['title' => 'Members-only handbook'])->published()->create();
    foreach (range(1, 260) as $index) {
        Page::factory()->site($domain->site)->withTranslations($domain->language, data: ['title' => sprintf('Public page %03d', $index)])->published()->create();
    }

    $restrictedUrl = agentPageUrl($restricted);
    $restrictedUrl->forceFill(['url' => '/0-members/handbook'])->save();
    $eligibility = new RecordingAgentReadEligibility([$restrictedUrl->refresh()->fullUrl()]);
    resolve(PublicPageReadEligibilityRegistry::class)->register($eligibility);

    // Drain the work that page creation deferred to the end of a request.
    $this->getJson($domain->full_url . '/agent/v1/navigation')->assertOk();
    $earlierBatches = count($eligibility->batches);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $first = BrowsePublicSiteMapAction::run($domain->site, $domain->language);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // One contributor call over one scan chunk answers page one: the rest of the site is never read.
    expect($first->items())->toHaveCount(50)
        ->and(array_column($first->items(), 'title'))->not->toContain('Members-only handbook')
        ->and($first->hasMorePages())->toBeTrue()
        ->and(array_slice($eligibility->batches, $earlierBatches))->toHaveCount(1)
        ->and(count(array_slice($eligibility->batches, $earlierBatches)[0] ?? []))->toBeLessThanOrEqual(200)
        ->and($queries)->toBeLessThan(15);

    $titles = [];
    foreach (range(1, 6) as $page) {
        $response = $this->getJson($domain->full_url . '/agent/v1/navigation?page=' . $page)->assertOk()->assertDontSee('Members-only handbook');
        array_push($titles, ...collect($response->json('data'))->pluck('title')->all());
        $expectedNext = $page < 6;
        expect($response->json('links.next') !== null)->toBe($expectedNext);
    }

    expect($titles)->toHaveCount(260)
        ->and(array_unique($titles))->toHaveCount(260);
});

it('resolves relative candidates against the site domain before any contributor sees them', function (): void {
    $domain = SiteDomain::factory()->default()->create();
    $eligibility = new RecordingAgentReadEligibility;
    $registry = resolve(PublicPageReadEligibilityRegistry::class)->register($eligibility);

    $decisions = $registry->allowsMany([
        new PublicPageReadCandidateData('/catalogue', siteId: $domain->site_id, languageId: $domain->language_id),
        new PublicPageReadCandidateData('/catalogue'),
        new PublicPageReadCandidateData('//elsewhere.test/catalogue', siteId: $domain->site_id),
        new PublicPageReadCandidateData('https://agent.test/catalogue', siteId: $domain->site_id),
    ]);

    expect($decisions)->toBe([true, false, false, true])
        ->and($eligibility->batches)->toBe([[rtrim($domain->full_url, '/') . '/catalogue', 'https://agent.test/catalogue']]);
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
