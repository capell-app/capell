<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Contracts\PageVariantNegotiator;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Support\Render\PageVariantNegotiatorRegistry;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    config()->set('capell-frontend.html_cache', false);
    config()->set('capell-frontend.write_html_cache', false);
});

it('discovers page variant negotiators through the stable container tag', function (): void {
    $negotiator = new class implements PageVariantNegotiator
    {
        public function variant(Request $request, FrontendRenderContextData $context): ?Response
        {
            return response('tagged variant');
        }
    };

    $container = new Container;
    $container->instance('test.page-variant-negotiator', $negotiator);
    $container->tag('test.page-variant-negotiator', PageVariantNegotiator::TAG);
    $registry = new PageVariantNegotiatorRegistry($container);
    $request = Request::create('/about.md');
    $context = new FrontendRenderContextData(null, null, null, null, null);

    expect($registry->resolutionPath($request, '/about.md'))->toBe('/about')
        ->and($registry->negotiate($request, $context)?->getContent())->toBe('tagged variant');
});

it('does not invoke page variant negotiators for non get requests', function (): void {
    $capture = new stdClass;
    $capture->called = false;

    $registry = new PageVariantNegotiatorRegistry(new Container);
    $registry->register(new readonly class($capture) implements PageVariantNegotiator
    {
        public function __construct(private stdClass $capture) {}

        public function variant(Request $request, FrontendRenderContextData $context): ?Response
        {
            $this->capture->called = true;

            return response('unexpected');
        }
    });

    $request = Request::create('/about.md', 'POST');
    $context = new FrontendRenderContextData(null, null, null, null, null);

    expect($registry->resolutionPath($request, '/about.md'))->toBe('/about.md')
        ->and($registry->negotiate($request, $context))->toBeNull()
        ->and($capture->called)->toBeFalse();
});

it('lets a negotiator serve a markdown variant of a resolved page', function (): void {
    $site = Site::factory()->withTranslations(siteDomainData: [
        'default' => true,
        'domain' => 'example.test',
        'path' => null,
        'scheme' => 'https',
    ])->createOne();

    $page = Page::factory()
        ->published()
        ->site($site)
        ->withTranslations($site->language, ['title' => 'About'], slug: 'about')
        ->createOne();

    $capture = new stdClass;
    $capture->pageId = null;

    resolve(PageVariantNegotiatorRegistry::class)->register(
        new readonly class($capture) implements PageVariantNegotiator
        {
            public function __construct(private stdClass $capture) {}

            public function variant(Request $request, FrontendRenderContextData $context): ?Response
            {
                $this->capture->pageId = $context->page?->getKey();

                if (! str_ends_with($request->path(), '.md')) {
                    return null;
                }

                return response('# About', Response::HTTP_OK, [
                    'Content-Type' => 'text/markdown; charset=utf-8',
                ]);
            }
        },
    );

    $this->get('https://example.test/about.md')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/markdown; charset=utf-8')
        ->assertSee('# About', false);

    expect($capture->pageId)->toBe($page->getKey());
});

it('does not fall through to duplicate html when no negotiator handles a markdown variant', function (): void {
    $site = Site::factory()->withTranslations(siteDomainData: [
        'default' => true,
        'domain' => 'example.test',
        'path' => null,
        'scheme' => 'https',
    ])->createOne();

    Page::factory()
        ->published()
        ->site($site)
        ->withTranslations($site->language, ['title' => 'About'], slug: 'about')
        ->createOne();

    resolve(PageVariantNegotiatorRegistry::class)->register(
        new class implements PageVariantNegotiator
        {
            public function variant(Request $request, FrontendRenderContextData $context): ?Response
            {
                return null;
            }
        },
    );

    $this->get('https://example.test/about.md')
        ->assertNoContent(Response::HTTP_NOT_FOUND);
});
