<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Support\Publishing\PublicationDateGuard;
use Capell\Core\Support\Publishing\PublishSentinel;
use Capell\Frontend\Actions\Fragments\ResolvePublicFragmentContentVersionAction;
use Capell\Frontend\Actions\Fragments\ResolvePublicFragmentContextAction;
use Capell\Frontend\Data\Fragments\PublicFragmentReferenceData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * @return array{language: Language, site: Site, layout: Layout, page: Page, pageUrl: PageUrl}
 */
function publicFragmentContextFixture(): array
{
    $language = Language::factory()->english()->create();
    $site = Site::factory()
        ->language($language)
        ->withTranslations($language)
        ->create();
    $layout = Layout::factory()->site($site)->create(['status' => true]);
    $page = Page::factory()
        ->site($site)
        ->layout($layout)
        ->withTranslations($language, ['title' => 'Published fragment page'])
        ->create([
            'visible_from' => CarbonImmutable::now()->subDay(),
            'visible_until' => null,
        ]);
    $pageUrl = PageUrl::query()
        ->where('pageable_type', $page->getMorphClass())
        ->where('pageable_id', $page->getKey())
        ->where('site_id', $site->getKey())
        ->where('language_id', $language->getKey())
        ->first()
        ?? PageUrl::factory()
            ->page($page)
            ->language($language)
            ->site($site)
            ->create(['url' => '/fragment-page', 'status' => true]);

    return ['language' => $language, 'site' => $site, 'layout' => $layout, 'page' => $page, 'pageUrl' => $pageUrl];
}

/**
 * @param  array{language: Language, site: Site, layout: Layout, page: Page, pageUrl: PageUrl}  $fixture
 * @param  array<string, mixed>  $overrides
 */
function publicFragmentContextReference(array $fixture, array $overrides = []): PublicFragmentReferenceData
{
    $ownerContext = ['layoutId' => $fixture['layout']->getKey(), 'widgetKey' => 'hero'];
    $contentVersion = ResolvePublicFragmentContentVersionAction::run(
        $fixture['page'],
        $fixture['site'],
        $fixture['language'],
        $fixture['layout'],
        $ownerContext,
    );

    return new PublicFragmentReferenceData(
        owner: $overrides['owner'] ?? 'layout-builder',
        formatVersion: $overrides['formatVersion'] ?? 1,
        pageableType: $overrides['pageableType'] ?? $fixture['page']->getMorphClass(),
        pageableId: $overrides['pageableId'] ?? $fixture['page']->getKey(),
        siteId: $overrides['siteId'] ?? $fixture['site']->getKey(),
        languageId: $overrides['languageId'] ?? $fixture['language']->getKey(),
        contentVersion: $overrides['contentVersion'] ?? $contentVersion,
        ownerContext: $overrides['ownerContext'] ?? $ownerContext,
    );
}

it('resolves an authoritative published public fragment context', function (): void {
    CarbonImmutable::setTestNow('2026-07-14 12:00:00');
    $fixture = publicFragmentContextFixture();
    $reference = publicFragmentContextReference($fixture);
    $freshPage = Page::query()->findOrFail($fixture['page']->getKey());
    $freshSite = Site::query()->findOrFail($fixture['site']->getKey());
    $freshLanguage = Language::query()->findOrFail($fixture['language']->getKey());
    $freshLayout = Layout::query()->findOrFail($fixture['layout']->getKey());
    $freshContentVersion = ResolvePublicFragmentContentVersionAction::run(
        $freshPage,
        $freshSite,
        $freshLanguage,
        $freshLayout,
        $reference->ownerContext,
    );

    expect($freshContentVersion)->toBe($reference->contentVersion);

    $context = ResolvePublicFragmentContextAction::run($reference);

    expect($context->page->is($fixture['page']))->toBeTrue()
        ->and($context->site->is($fixture['site']))->toBeTrue()
        ->and($context->language->is($fixture['language']))->toBeTrue()
        ->and($context->page->relationLoaded('layout'))->toBeTrue()
        ->and($context->reference->owner)->toBe('layout-builder');
});

it('rejects every non-public publication state', function (Closure $mutate): void {
    CarbonImmutable::setTestNow('2026-07-14 12:00:00');
    $fixture = publicFragmentContextFixture();
    $reference = publicFragmentContextReference($fixture);

    PublicationDateGuard::allow(fn (): mixed => $mutate($fixture['page']));

    expect(fn (): mixed => ResolvePublicFragmentContextAction::run($reference))
        ->toThrow(ModelNotFoundException::class);
})->with([
    'draft' => fn (Page $page) => $page->forceFill([
        'visible_from' => PublishSentinel::draftValue(),
        'visible_until' => null,
    ])->save(),
    'scheduled' => fn (Page $page) => $page->forceFill([
        'visible_from' => CarbonImmutable::now()->addDay(),
        'visible_until' => null,
    ])->save(),
    'expired' => fn (Page $page) => $page->forceFill([
        'visible_from' => CarbonImmutable::now()->subWeek(),
        'visible_until' => CarbonImmutable::now()->subSecond(),
    ])->save(),
    'deleted' => fn (Page $page) => $page->delete(),
]);

it('rejects a missing page identity', function (): void {
    $fixture = publicFragmentContextFixture();

    expect(fn (): mixed => ResolvePublicFragmentContextAction::run(publicFragmentContextReference($fixture, [
        'pageableId' => 999999,
    ])))->toThrow(ModelNotFoundException::class);
});

it('rejects a cross-site reference', function (): void {
    $fixture = publicFragmentContextFixture();
    $otherSite = Site::factory()->withTranslations()->create();

    expect(fn (): mixed => ResolvePublicFragmentContextAction::run(publicFragmentContextReference($fixture, [
        'siteId' => $otherSite->getKey(),
    ])))->toThrow(ModelNotFoundException::class);
});

it('rejects cross-language and disabled-language references', function (): void {
    $fixture = publicFragmentContextFixture();
    $otherLanguage = Language::factory()->french()->create();

    expect(fn (): mixed => ResolvePublicFragmentContextAction::run(publicFragmentContextReference($fixture, [
        'languageId' => $otherLanguage->getKey(),
    ])))->toThrow(ModelNotFoundException::class);

    $fixture['language']->update(['status' => false]);

    expect(fn (): mixed => ResolvePublicFragmentContextAction::run(publicFragmentContextReference($fixture)))
        ->toThrow(ModelNotFoundException::class);
});

it('rejects pages without an eligible public URL', function (): void {
    $fixture = publicFragmentContextFixture();
    $reference = publicFragmentContextReference($fixture);
    $fixture['pageUrl']->update(['status' => false]);

    expect(fn (): mixed => ResolvePublicFragmentContextAction::run($reference))
        ->toThrow(ModelNotFoundException::class);
});

it('rejects pages whose blueprint is disabled or inaccessible', function (array $attributes): void {
    $fixture = publicFragmentContextFixture();
    $reference = publicFragmentContextReference($fixture);
    $fixture['page']->blueprint()->update($attributes);

    expect(fn (): mixed => ResolvePublicFragmentContextAction::run($reference))
        ->toThrow(ModelNotFoundException::class);
})->with([
    'disabled' => [['status' => false]],
    'inaccessible' => [['meta' => ['accessible' => false]]],
]);

it('rejects layouts not owned by the page and site', function (): void {
    $fixture = publicFragmentContextFixture();
    $otherLayout = Layout::factory()->create(['status' => true]);

    expect(fn (): mixed => ResolvePublicFragmentContextAction::run(publicFragmentContextReference($fixture, [
        'ownerContext' => ['layoutId' => $otherLayout->getKey(), 'widgetKey' => 'hero'],
    ])))->toThrow(ModelNotFoundException::class);

    $fixture['layout']->update(['site_id' => Site::factory()->withTranslations()->create()->getKey()]);

    expect(fn (): mixed => ResolvePublicFragmentContextAction::run(publicFragmentContextReference($fixture)))
        ->toThrow(ModelNotFoundException::class);
});

it('revokes a stale content version after public content changes', function (): void {
    $fixture = publicFragmentContextFixture();
    $reference = publicFragmentContextReference($fixture);
    $fixture['page']->translations()->where('language_id', $fixture['language']->getKey())->update([
        'title' => 'Changed public fragment page',
    ]);

    expect(fn (): mixed => ResolvePublicFragmentContextAction::run($reference))
        ->toThrow(ModelNotFoundException::class);

    $freshReference = publicFragmentContextReference($fixture);

    expect(ResolvePublicFragmentContextAction::run($freshReference)->page->is($fixture['page']))
        ->toBeTrue();
});

it('returns the same generic model-not-found outcome for every rejection', function (): void {
    $fixture = publicFragmentContextFixture();
    $references = [
        publicFragmentContextReference($fixture, ['pageableType' => 'unknown-owner-model']),
        publicFragmentContextReference($fixture, ['contentVersion' => 'stale']),
        publicFragmentContextReference($fixture, ['ownerContext' => ['layoutId' => 999999]]),
    ];

    foreach ($references as $reference) {
        try {
            ResolvePublicFragmentContextAction::run($reference);
            $this->fail('Expected a model-not-found outcome.');
        } catch (ModelNotFoundException $exception) {
            expect($exception->getMessage())->toBe('');
        }
    }
});
