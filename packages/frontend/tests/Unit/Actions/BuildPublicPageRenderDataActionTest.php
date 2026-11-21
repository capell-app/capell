<?php

declare(strict_types=1);

use Capell\Core\Contracts\BladeComponentResolverInterface;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Data\RenderableDefinitionData;
use Capell\Core\Data\Rendering\FrontendRuntimeManifestData;
use Capell\Core\Enums\Rendering\RenderingStrategyEnum;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $manifestPath = public_path('vendor/capell-frontend/manifest.json');

    if (! File::exists($manifestPath)) {
        File::ensureDirectoryExists(dirname($manifestPath));
        File::copy(dirname(__DIR__, 3) . '/publishes/build/manifest.json', $manifestPath);
        test()->beforeApplicationDestroyed(fn (): bool => File::delete($manifestPath));
    }
});

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

    $manifestPath = public_path('build/manifest.json');
    $originalManifest = File::exists($manifestPath) ? File::get($manifestPath) : null;
    test()->beforeApplicationDestroyed(function () use ($manifestPath, $originalManifest): void {
        if ($originalManifest === null) {
            File::delete($manifestPath);
        } else {
            File::put($manifestPath, $originalManifest);
        }
    });
    File::ensureDirectoryExists(public_path('build'));
    File::put(public_path('build/manifest.json'), json_encode([
        'resources/css/app.css' => ['file' => 'assets/app-test.css', 'isEntry' => true],
    ], JSON_THROW_ON_ERROR));

    $language = Language::factory()->createOne(['code' => 'id']);
    $site = Site::factory()->createOne(['language_id' => $language->id]);
    $theme = Theme::factory()->createOne(['key' => 'render-data-theme', 'meta' => ['assets' => ['resources/css/app.css']]]);
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

    // Public Blade, fragments and exports read this object, so no model may
    // reach them through it, however deeply nested.
    expect(eloquentModelsWithin($renderData))->toBe([])
        ->and($renderData->context?->site?->name)->toBe($site->name)
        ->and($renderData->context?->language?->code)->toBe($language->code)
        ->and($renderData->context?->layout?->key)->toBe($layout->key)
        ->and($renderData->context?->theme?->key)->toBe($theme->key)
        ->and($renderData->context?->page?->name)->toBe($page->name)
        ->and($renderData->runtimeManifest->usesLivewire)->toBeFalse()
        ->and($renderData->resourcePlan->headResources)->toHaveCount(2)
        ->and(collect($renderData->resourcePlan->headResources)
            ->first(fn (object $resource): bool => str_ends_with($resource->url, '/build/assets/app-test.css')))
        ->not->toBeNull()
        ->and($renderData->surrogateKeys)->toBe([
            'page-' . $page->getKey(),
            'site-' . $site->getKey(),
            'lang-id',
        ]);

    $publicContext = $renderData->context?->toArray() ?? [];
    expect($publicContext)->toHaveKeys(['page', 'site', 'language', 'layout', 'theme'])->not->toHaveKey('id');
    foreach ($publicContext as $projection) {
        expect($projection)->toBeArray()->not->toHaveKey('id');
    }

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

/**
 * Class names of every Eloquent model or Pageable reachable from the value
 * through properties of any visibility and arrays.
 *
 * @return list<class-string>
 */
function eloquentModelsWithin(mixed $value, ?WeakMap $seen = null): array
{
    $seen ??= new WeakMap;

    if (is_array($value)) {
        $found = [];

        foreach ($value as $item) {
            array_push($found, ...eloquentModelsWithin($item, $seen));
        }

        return $found;
    }

    if (! is_object($value) || isset($seen[$value])) {
        return [];
    }

    if ($value instanceof Model || $value instanceof Pageable) {
        return [$value::class];
    }

    $seen[$value] = true;
    $found = [];

    foreach (new ReflectionObject($value)->getProperties() as $property) {
        if (! $property->isStatic() && $property->isInitialized($value)) {
            array_push($found, ...eloquentModelsWithin($property->getValue($value), $seen));
        }
    }

    return $found;
}
