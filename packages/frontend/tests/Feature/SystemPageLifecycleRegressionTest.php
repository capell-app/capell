<?php

declare(strict_types=1);

use Capell\Core\Contracts\Themes\ThemePreviewRendererInterface;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Creator\PageCreator;
use Capell\Frontend\Actions\GenerateErrorPageCacheAction;
use Capell\Frontend\Actions\GenerateMaintenancePageCacheAction;
use Capell\Frontend\Actions\RegenerateSiteErrorPagesAction;
use Capell\Frontend\Contracts\StaticErrorPageStore;
use Capell\Frontend\Contracts\StaticMaintenancePageStore;
use Capell\Frontend\Enums\FrontendRenderAudience;
use Capell\Frontend\Events\FrontendRenderPreparing;
use Capell\Frontend\Exceptions\PublicRenderContractViolationException;
use Capell\Frontend\Support\State\FrontendState;
use Capell\Frontend\Support\Themes\FrontendThemePreviewRenderer;
use Capell\Frontend\Tests\Support\RecordingStaticErrorPageStore;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpFoundation\Response;

it('preserves a system page identity and authored layout when regenerated', function (bool $maintenance, bool $customisedLayout): void {
    $site = Model::withoutEvents(fn (): Site => Site::factory()->withTranslations()->create());
    $creator = resolve(PageCreator::class);
    $original = $maintenance ? $creator->createMaintenancePage($site) : $creator->createErrorPage($site);
    $customLayout = $customisedLayout ? Layout::factory()->create() : $original->layout;
    Model::withoutEvents(fn (): bool => $original->forceFill([
        'layout_id' => $customLayout->id,
        'name' => 'Authored recovery',
        'meta' => ['authored' => 'preserve', 'robots' => ['nofollow' => true]],
    ])->save());
    $canonicalId = $original->pageUrls()->whereNull('type')->sole()->getKey();
    $translation = $original->translations()->firstOrFail();
    $translationAttributes = $translation->getAttributes();

    $regenerated = $maintenance ? $creator->createMaintenancePage($site) : $creator->createErrorPage($site);

    expect($regenerated->getKey())->toBe($original->getKey())
        ->and($regenerated->layout_id)->toBe($customLayout->id)
        ->and($regenerated->name)->toBe('Authored recovery')
        ->and(capellJsonKeysSorted($regenerated->meta))->toBe(capellJsonKeysSorted([
            'robots' => ['noindex' => true, 'nofollow' => true],
            'authored' => 'preserve',
        ]))
        ->and($regenerated->pageUrls()->whereNull('type')->sole()->getKey())->toBe($canonicalId)
        ->and(expectPresent($translation->fresh())->getAttributes())->toBe($translationAttributes)
        ->and(Page::query()->where('site_id', $site->id)->where('blueprint_id', $original->blueprint_id)->count())->toBe(1);
})->with(['error' => false, 'maintenance' => true])->with(['system layout' => false, 'custom layout' => true]);

it('preserves every caller frontend field across background system-page generation', function (bool $maintenance): void {
    $store = new class extends RecordingStaticErrorPageStore implements StaticMaintenancePageStore {};
    app()->instance(StaticErrorPageStore::class, $store);
    app()->instance(StaticMaintenancePageStore::class, $store);

    $language = Language::factory()->english()->create();
    $site = Site::factory()->withTranslations($language)->create(['language_id' => $language->id]);
    $callerPage = resolve(PageCreator::class)->createErrorPage($site);
    $state = resolve(FrontendState::class);
    $state->reset()->withSite($site)->withPage($callerPage)
        ->withLayout($callerPage->layout)->withTheme($site->theme)
        ->withDomain($site->siteDomains->firstOrFail())->withLanguage(new Language(['code' => 'ar']))
        ->withParams(['caller' => 'value'])->withSlug('caller-slug')
        ->withRelativePath('/caller-path')->setEffectiveUrl('https://caller.test/path')
        ->setRevisionPageId(123)->markAsError()->setFrontendData('caller', ['nested' => true]);
    $before = clone $state;

    $writesBefore = $store->writes;
    if ($maintenance) {
        GenerateMaintenancePageCacheAction::run($site);
    } else {
        RegenerateSiteErrorPagesAction::run((int) $site->getKey());
    }

    expect($store->writes)->toBeGreaterThan($writesBefore)
        ->and($state)->toEqual($before);
})->with(['error' => false, 'maintenance' => true]);

it('restores caller context when a preview throws after seeding its context', function (): void {
    $language = Language::factory()->english()->create();
    $site = Site::factory()->withTranslations($language)->create(['language_id' => $language->id]);
    $callerPage = resolve(PageCreator::class)->createErrorPage($site);
    $state = resolve(FrontendState::class);
    $state->reset()->withSite($site)->withPage($callerPage)
        ->withLayout($callerPage->layout)->withTheme($site->theme)
        ->withDomain($site->siteDomains->firstOrFail())->withLanguage(new Language(['code' => 'ar']))
        ->withParams(['caller' => 'value'])->withSlug('caller-slug')
        ->withRelativePath('/caller-path')->setEffectiveUrl('https://caller.test/path')
        ->setRevisionPageId(123)->markAsError()->setFrontendData('caller', ['nested' => true]);
    $before = clone $state;
    Event::listen(FrontendRenderPreparing::class, function (): never {
        throw new RuntimeException('Preview stopped.');
    });

    expect(fn (): Response => resolve(FrontendThemePreviewRenderer::class)->render($site->theme, $site, $callerPage, $language))
        ->toThrow(RuntimeException::class, 'Preview stopped.');
    expect($state)->toEqual($before);
});

it('restores shared model relations after preview seeding', function (bool $throws, bool $loaded, bool $pageOnly): void {
    $language = Language::factory()->english()->create();
    $site = Site::factory()->withTranslations($language)->create(['language_id' => $language->id]);
    $page = resolve(PageCreator::class)->createErrorPage($site);
    $layout = $page->layout;
    $theme = $site->theme;
    $domain = $site->siteDomains->firstOrFail();
    if ($loaded) {
        $site->setRelation('theme', $theme)->setRelation('siteDomain', $domain);
        $layout->setRelation('theme', $theme);
    } else {
        $site->unsetRelation('theme')->unsetRelation('siteDomain');
        $layout->unsetRelation('theme');
    }

    $page->setRelation('site', $site);
    $site->setRelation('pages', new Collection([$page]));
    $siteRelations = $site->getRelations();
    $layoutRelations = $layout->getRelations();
    $pageRelations = $page->getRelations();
    $state = resolve(FrontendState::class)->reset()->withPage($page);
    if (! $pageOnly) {
        $state->withSite($site)->withLayout($layout)->withTheme($theme)->withDomain($domain)->withLanguage($language);
    }

    $previewTheme = Theme::factory()->create();
    $previewDomain = new SiteDomain(['domain' => 'preview.test', 'scheme' => 'https']);
    if ($throws) {
        Event::listen(FrontendRenderPreparing::class, function (): never {
            throw new RuntimeException('Preview stopped.');
        });
    }

    $render = fn (): Response => resolve(FrontendThemePreviewRenderer::class)->render($previewTheme, $site, $page, $language, $previewDomain);
    if ($throws) {
        expect($render)->toThrow(RuntimeException::class, 'Preview stopped.');
    } else {
        expect($render()->getStatusCode())->toBe(200);
    }

    expect($state->site())->toBe($pageOnly ? null : $site)
        ->and($state->layout())->toBe($pageOnly ? null : $layout)
        ->and($state->page())->toBe($page)
        ->and($state->theme())->toBe($pageOnly ? null : $theme)
        ->and($state->domain())->toBe($pageOnly ? null : $domain)
        ->and($site->getRelations())->toBe($siteRelations)
        ->and($layout->getRelations())->toBe($layoutRelations)
        ->and($page->getRelations())->toBe($pageRelations);
})->with(['success' => false, 'exception' => true])->with(['loaded' => true, 'unloaded' => false])->with(['full caller' => false, 'page only caller' => true]);

it('validates static system pages as public and restores the caller audience', function (bool $maintenance, FrontendRenderAudience $audience, string $html): void {
    $site = Model::withoutEvents(fn (): Site => Site::factory()->withTranslations()->create());
    $store = new class extends RecordingStaticErrorPageStore implements StaticMaintenancePageStore {};
    app()->instance(StaticErrorPageStore::class, $store);
    app()->instance(StaticMaintenancePageStore::class, $store);

    $renderer = new readonly class($html) implements ThemePreviewRendererInterface
    {
        public function __construct(private string $html) {}

        #[Override]
        public function render(Theme $theme, Site $site, Page $page, ?Language $language = null, ?SiteDomain $siteDomain = null): Response
        {
            return new Response($this->html);
        }
    };
    app()->instance(ThemePreviewRendererInterface::class, $renderer);
    $state = resolve(FrontendState::class)->reset()->setFrontendData('renderAudience', $audience);
    $before = clone $state;
    $generate = fn (): array => $maintenance
        ? GenerateMaintenancePageCacheAction::run($site)
        : GenerateErrorPageCacheAction::run($site);

    if ($html === '<h1>Safe recovery</h1>') {
        expect($generate())->not->toBeEmpty();
        expect($store->files)->not->toBeEmpty();
        foreach ($store->files as $contents) {
            expect($contents)->toBe($html);
        }
    } else {
        expect($generate)->toThrow(PublicRenderContractViolationException::class);
        expect($store->files)->toBeEmpty();
    }

    expect($state)->toEqual($before);
})->with(['error' => false, 'maintenance' => true])
    ->with([FrontendRenderAudience::Preview, FrontendRenderAudience::Admin])
    ->with([
        'safe' => '<h1>Safe recovery</h1>',
        'internal' => '<h1>Recovery</h1><!-- vendor/capell-app/admin -->',
        'authoring' => '<div data-capell-editor="true">Recovery</div>',
    ]);
