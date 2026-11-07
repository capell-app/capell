<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Frontend\Data\FrontendContext;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Facades\Frontend;
use Capell\Frontend\Support\Render\HeadMetadataRegistry;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Illuminate\Support\Facades\Blade;

it('renders default social metadata and structured data without authoring state', function (): void {
    $language = Language::factory()->createOne();
    $theme = Theme::factory()->defaultMeta()->create();
    $site = Site::factory()->language($language)->theme($theme)->withTranslations($language, ['title' => 'Example Site'])->create();
    $layout = Layout::factory()->site($site)->create();
    $page = Page::factory()->site($site)->layout($layout)->withTranslations($language, ['title' => 'Example Page', 'meta' => ['description' => 'Page description']])->create();
    $site->load(['language', 'siteDomain', 'siteDomains', 'translation']);
    $page->load(['pageUrl.siteDomain', 'pageUrls.language', 'pageUrls.siteDomain', 'translation']);

    Frontend::swap(new FrontendContext(site: $site, language: $language, page: $page, layout: $layout, theme: $theme, params: [], slug: null));
    $html = Blade::render('<x-capell::app.head :livewire-enabled="false" />');
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);

    foreach (['og:title', 'og:description', 'og:type', 'og:url', 'og:site_name'] as $key) {
        expect(($xpath->query('//meta[@property="' . $key . '"]') ?: throw new RuntimeException('Invalid XPath'))->length)->toBe(1);
    }

    foreach (['twitter:card', 'twitter:title', 'twitter:description'] as $key) {
        expect(($xpath->query('//meta[@name="' . $key . '"]') ?: throw new RuntimeException('Invalid XPath'))->length)->toBe(1);
    }

    expect(($xpath->query('//script[@type="application/ld+json"]') ?: throw new RuntimeException('Invalid XPath'))->length)->toBe(1)
        ->and($html)->not->toContain('wire:snapshot')->not->toContain('wire:id')->not->toContain('data-capell-edit');
});

it('replaces one keyed social value without suppressing the other defaults', function (): void {
    $language = Language::factory()->createOne();
    $theme = Theme::factory()->defaultMeta()->create();
    $site = Site::factory()->language($language)->theme($theme)->withTranslations($language, ['title' => 'Example Site'])->create();
    $layout = Layout::factory()->site($site)->create();
    $page = Page::factory()->site($site)->layout($layout)->withTranslations($language, ['title' => 'Example Page'])->create();
    $site->load(['language', 'siteDomain', 'siteDomains', 'translation']);
    $page->load(['pageUrl.siteDomain', 'pageUrls.language', 'pageUrls.siteDomain', 'translation']);
    Frontend::swap(new FrontendContext(site: $site, language: $language, page: $page, layout: $layout, theme: $theme, params: [], slug: null));
    resolve(HeadMetadataRegistry::class)->register('og:title', 'Replacement title');

    $html = Blade::render('<x-capell::app.head :livewire-enabled="false" />');
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $titleTags = $xpath->query('//meta[@property="og:title"]') ?: throw new RuntimeException('Invalid XPath');
    $titleTag = $titleTags->item(0);
    throw_unless($titleTag instanceof DOMElement, RuntimeException::class, 'Missing Open Graph title');

    $cardTags = $xpath->query('//meta[@name="twitter:card"]') ?: throw new RuntimeException('Invalid XPath');
    expect($titleTags->length)->toBe(1)
        ->and($titleTag->getAttribute('content'))->toBe('Replacement title')
        ->and($cardTags->length)->toBe(1);
});

it('preserves authored robots directives without duplicating the tag', function (): void {
    $language = Language::factory()->createOne();
    $theme = Theme::factory()->defaultMeta()->create();
    $site = Site::factory()->language($language)->theme($theme)->withTranslations($language, ['title' => 'Example Site'])->create();
    $layout = Layout::factory()->site($site)->create();
    $page = Page::factory()->site($site)->layout($layout)->withTranslations($language, ['title' => 'Example Page'])->create(['meta' => ['meta_tags' => '<meta name="robots" content="noindex">']]);
    $site->load(['language', 'siteDomain', 'siteDomains', 'translation']);
    $page->load(['pageUrl.siteDomain', 'pageUrls.language', 'pageUrls.siteDomain', 'translation']);
    Frontend::swap(new FrontendContext(site: $site, language: $language, page: $page, layout: $layout, theme: $theme, params: [], slug: null));
    $html = Blade::render('<x-capell::app.head :livewire-enabled="false" />');
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $tags = new DOMXPath($document)->query('//meta[@name="robots"]') ?: throw new RuntimeException('Invalid XPath');
    $tag = $tags->item(0);
    throw_unless($tag instanceof DOMElement, RuntimeException::class, 'Missing robots tag');

    expect($tags->length)->toBe(1)->and($tag->getAttribute('content'))->toBe('noindex');
});

it('folds managed head hook tags while preserving unrelated output', function (): void {
    $language = Language::factory()->createOne();
    $theme = Theme::factory()->defaultMeta()->create();
    $site = Site::factory()->language($language)->theme($theme)->withTranslations($language, ['title' => 'Example Site'])->create();
    $layout = Layout::factory()->site($site)->create();
    $page = Page::factory()->site($site)->layout($layout)->withTranslations($language, ['title' => 'Example Page'])->create();
    $site->load(['language', 'siteDomain', 'siteDomains', 'translation']);
    $page->load(['pageUrl.siteDomain', 'pageUrls.language', 'pageUrls.siteDomain', 'translation']);
    Frontend::swap(new FrontendContext(site: $site, language: $language, page: $page, layout: $layout, theme: $theme, params: [], slug: null));
    resolve(RenderHookRegistry::class)->register(RenderHookLocation::HeadClose, fn (): string => '<meta name="robots" content="noindex"><meta property="og:title" content="Hook title"><link rel="author" href="/author">');
    $html = Blade::render('<x-capell::app.head :livewire-enabled="false" />');
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $robots = $xpath->query('//meta[@name="robots"]') ?: throw new RuntimeException('Invalid XPath');
    $titles = $xpath->query('//meta[@property="og:title"]') ?: throw new RuntimeException('Invalid XPath');
    $robot = $robots->item(0);
    $title = $titles->item(0);
    throw_if(! $robot instanceof DOMElement || ! $title instanceof DOMElement, RuntimeException::class, 'Missing metadata');

    expect($robots->length)->toBe(1)->and($robot->getAttribute('content'))->toBe('noindex')
        ->and($titles->length)->toBe(1)->and($title->getAttribute('content'))->toBe('Hook title')
        ->and($html)->toContain('<link rel="author" href="/author">');
});

it('encodes a hydrated page graph safely as JSON', function (): void {
    $language = Language::factory()->createOne();
    $theme = Theme::factory()->defaultMeta()->create();
    $site = Site::factory()->language($language)->theme($theme)->withTranslations($language, ['title' => 'Example Site'])->create();
    $layout = Layout::factory()->site($site)->create();
    $page = Page::factory()->site($site)->layout($layout)->withTranslations($language, ['title' => 'Quote " café </script>'])->create(['meta' => ['canonical_url' => 'https://example.test/page?q=</script>']]);
    $site->load(['language', 'siteDomain', 'siteDomains', 'translation']);
    $page->load(['pageUrl.siteDomain', 'pageUrls.language', 'pageUrls.siteDomain', 'translation']);
    Frontend::swap(new FrontendContext(site: $site, language: $language, page: $page, layout: $layout, theme: $theme, params: [], slug: null));
    $html = Blade::render('<x-capell::app.head :livewire-enabled="false" />');
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $scripts = new DOMXPath($document)->query('//script[@type="application/ld+json"]') ?: throw new RuntimeException('Invalid XPath');
    expect($scripts->length)->toBe(1);
    $script = $scripts->item(0);
    throw_unless($script instanceof DOMElement, RuntimeException::class, 'Missing JSON-LD');

    $json = $script->textContent;
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    $graph = $decoded['@graph'];
    expect($decoded['@context'])->toBe('https://schema.org')
        ->and(array_column($graph, '@type'))->toBe(['WebSite', 'WebPage'])
        ->and($graph[1]['name'])->toBe('Quote " café ')
        ->and($graph[1]['url'])->toBe('https://example.test/page?q=</script>')
        ->and($json)->toContain('\\u003C\\/script\\u003E')->not->toContain('</script>');
});

it('leaves the agent schema script intact when it describes the page', function (): void {
    $language = Language::factory()->createOne();
    $theme = Theme::factory()->defaultMeta()->create();
    $site = Site::factory()->language($language)->theme($theme)->withTranslations($language, ['title' => 'Example Site'])->create();
    $layout = Layout::factory()->site($site)->create();
    $page = Page::factory()->site($site)->layout($layout)->withTranslations($language, ['title' => 'Example Page'])->create();
    $site->load(['language', 'siteDomain', 'siteDomains', 'translation']);
    $page->load(['pageUrl.siteDomain', 'pageUrls.language', 'pageUrls.siteDomain', 'translation']);
    Frontend::swap(new FrontendContext(site: $site, language: $language, page: $page, layout: $layout, theme: $theme, params: [], slug: null));
    resolve(RenderHookRegistry::class)->register(RenderHookLocation::HeadClose, fn (): string => '<script type="application/ld+json" data-capell-agent-schema>{"@context":"https://schema.org","@graph":[{"@type":"WebPage","name":"Agent page"}]}</script>');
    $html = Blade::render('<x-capell::app.head :livewire-enabled="false" />');

    expect(substr_count($html, 'data-capell-agent-schema'))->toBe(1)
        ->and($html)->toContain('"name":"Agent page"');
});

it('keeps page noindex when a site snippet allows indexing', function (): void {
    $language = Language::factory()->createOne();
    $theme = Theme::factory()->defaultMeta()->create();
    $site = Site::factory()->language($language)->theme($theme)->withTranslations($language, ['title' => 'Example Site'])->create(['meta' => ['meta_tags' => '<meta name="robots" content="index, follow">']]);
    $layout = Layout::factory()->site($site)->create();
    $page = Page::factory()->site($site)->layout($layout)->withTranslations($language, ['title' => 'Example Page'])->create(['meta' => ['robots' => ['noindex' => true]]]);
    $site->load(['language', 'siteDomain', 'siteDomains', 'translation']);
    $page->load(['pageUrl.siteDomain', 'pageUrls.language', 'pageUrls.siteDomain', 'translation']);
    Frontend::swap(new FrontendContext(site: $site, language: $language, page: $page, layout: $layout, theme: $theme, params: [], slug: null));
    $html = Blade::render('<x-capell::app.head :livewire-enabled="false" />');
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $tags = new DOMXPath($document)->query('//meta[@name="robots"]') ?: throw new RuntimeException('Invalid XPath');
    $tag = $tags->item(0);
    throw_unless($tag instanceof DOMElement, RuntimeException::class, 'Missing robots tag');
    expect($tags->length)->toBe(1)->and($tag->getAttribute('content'))->toBe('noindex, follow');
});

it('uses plain text for social titles', function (): void {
    $language = Language::factory()->createOne();
    $theme = Theme::factory()->defaultMeta()->create();
    $site = Site::factory()->language($language)->theme($theme)->withTranslations($language, ['title' => 'Site'])->create();
    $layout = Layout::factory()->site($site)->create();
    $page = Page::factory()->site($site)->layout($layout)->withTranslations($language, ['title' => '<b>Example</b>'])->create();
    $site->load(['language', 'siteDomain', 'siteDomains', 'translation']);
    $page->load(['pageUrl.siteDomain', 'pageUrls.language', 'pageUrls.siteDomain', 'translation']);
    Frontend::swap(new FrontendContext(site: $site, language: $language, page: $page, layout: $layout, theme: $theme, params: [], slug: null));
    $html = Blade::render('<x-capell::app.head :livewire-enabled="false" />');
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $titles = new DOMXPath($document)->query('//meta[@property="og:title"]');
    throw_if($titles === false, RuntimeException::class, 'Invalid XPath');

    $title = $titles->item(0);
    throw_unless($title instanceof DOMElement, RuntimeException::class, 'Missing title');
    expect($title->getAttribute('content'))->toBe('Example');
});
