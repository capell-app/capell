<?php

declare(strict_types=1);

use Capell\Core\Contracts\BladeComponentResolverInterface;
use Capell\Core\Data\RenderableDefinitionData;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Renderables\RenderableRegistry;
use Capell\Core\Tests\Support\View\Components\PackageAlert;
use Capell\Frontend\Actions\BuildPublicPageRenderDataAction;
use Capell\Frontend\Contracts\PublicContentWidgetPayloadBuilder;
use Capell\Frontend\Contracts\PublicLayoutGraphBuilder;
use Capell\Frontend\Contracts\PublicWidgetInteractionLocatorBuilder;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Enums\RenderingStrategyEnum;

it('builds public page render data from the resolved frontend render context', function (): void {
    app()->bind(BladeComponentResolverInterface::class, fn (): BladeComponentResolverInterface => new class implements BladeComponentResolverInterface
    {
        public function getClassComponentAliases(): array
        {
            return [
                'capell::widget.default' => PackageAlert::class,
            ];
        }

        public function getClassComponentNamespaces(): array
        {
            return [];
        }
    });

    resolve(RenderableRegistry::class)->register(new RenderableDefinitionData(
        key: 'capell.layout-block.default',
        type: 'layout-block',
        blade: 'capell::widget.default',
    ));

    $language = Language::factory()->createOne(['code' => 'en']);
    $site = Site::factory()->createOne(['language_id' => $language->id]);
    $theme = Theme::factory()->createOne(['meta' => ['assets' => ['resources/css/app.css']]]);
    $layout = Layout::factory()->site($site)->create([
        'key' => 'home',
        'containers' => [
            'main' => [
                'elements' => [
                    ['element_key' => 'page-content', 'occurrence' => 1],
                ],
            ],
        ],
    ]);
    $page = Page::factory()
        ->site($site)
        ->layout($layout)
        ->withTranslations($language, ['title' => 'Home'], slug: '/')
        ->create();

    app()->bind(PublicLayoutGraphBuilder::class, fn (): PublicLayoutGraphBuilder => new class implements PublicLayoutGraphBuilder
    {
        public function build(Layout $layout, Page $page, Language $language): stdClass
        {
            return (object) [
                'key' => $layout->key,
                'containers' => $layout->containers,
                'pageId' => $page->getKey(),
                'languageCode' => $language->code,
            ];
        }
    });

    $renderData = BuildPublicPageRenderDataAction::run(new FrontendRenderContextData(
        page: $page,
        site: $site,
        language: $language,
        layout: $layout,
        theme: $theme,
        runtimeManifest: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    ));

    expect($renderData->page)->toBe($page)
        ->and($renderData->site)->toBe($site)
        ->and($renderData->language)->toBe($language)
        ->and($renderData->runtimeManifest->usesLivewire)->toBeFalse()
        ->and($renderData->resourcePlan->headResources)->toHaveCount(1)
        ->and($renderData->resourcePlan->headResources[0]->url)->toEndWith('/resources/css/app.css')
        ->and($renderData->surrogateKeys)->toBe([
            'page-' . $page->getKey(),
            'site-' . $site->getKey(),
            'lang-en',
        ]);

    expect($renderData->layoutGraph?->key)->toBe('home')
        ->and($renderData->layoutGraph?->containers)->toHaveCount(1);
});

it('skips optional layout graph generation when no resolver is available', function (): void {
    app()->forgetInstance(PublicLayoutGraphBuilder::class);
    app()->offsetUnset(PublicLayoutGraphBuilder::class);

    $language = Language::factory()->createOne(['code' => 'en']);
    $site = Site::factory()->createOne(['language_id' => $language->id]);
    $layout = Layout::factory()->site($site)->create();
    $page = Page::factory()
        ->site($site)
        ->layout($layout)
        ->withTranslations($language, ['title' => 'Home'], slug: '/')
        ->create();

    $renderData = BuildPublicPageRenderDataAction::run(new FrontendRenderContextData(
        page: $page,
        site: $site,
        language: $language,
        layout: $layout,
        theme: Theme::factory()->createOne(),
        runtimeManifest: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    ));

    expect($renderData->layoutGraph)->toBeNull();
});

it('builds optional typed content widget payloads before public rendering', function (): void {
    $payload = new class
    {
        public string $title = 'Hydrated';
    };

    app()->bind(PublicContentWidgetPayloadBuilder::class, fn (): PublicContentWidgetPayloadBuilder => new readonly class($payload) implements PublicContentWidgetPayloadBuilder
    {
        public function __construct(private object $payload) {}

        public function build(FrontendRenderContextData $context): array
        {
            return ['widget-instance' => $this->payload];
        }

        public function fingerprint(): string
        {
            return 'test-payload-schema-v1';
        }
    });

    $renderData = BuildPublicPageRenderDataAction::run(new FrontendRenderContextData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: null,
    ));

    expect($renderData->contentWidgetPayload('widget-instance'))->toBe($payload)
        ->and($renderData->contentWidgetPayload('missing'))->toBeNull();
});

it('builds optional widget interaction locators before public Blade rendering', function (): void {
    app()->bind(PublicWidgetInteractionLocatorBuilder::class, fn (): PublicWidgetInteractionLocatorBuilder => new class implements PublicWidgetInteractionLocatorBuilder
    {
        public function build(FrontendRenderContextData $context): array
        {
            return ['target-instance' => 'https://example.test/_capell/layout-widgets/opaque'];
        }
    });

    $renderData = BuildPublicPageRenderDataAction::run(new FrontendRenderContextData(null, null, null, null, null));

    expect($renderData->widgetInteractionLocator('target-instance'))->toEndWith('/opaque')
        ->and($renderData->widgetInteractionLocator('missing'))->toBeNull();
});
