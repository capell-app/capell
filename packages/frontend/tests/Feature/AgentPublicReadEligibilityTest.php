<?php

declare(strict_types=1);

use Capell\Core\Actions\PublicRead\PageUrlIsPubliclyReadableAction;
use Capell\Core\Contracts\Agent\AgentPageSearch;
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

/** @param list<string> $restrictedUrls */
function restrictAgentReadsTo(array $restrictedUrls): void
{
    resolve(PublicPageReadEligibilityRegistry::class)->register(new readonly class($restrictedUrls) implements PublicPageReadEligibility
    {
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
