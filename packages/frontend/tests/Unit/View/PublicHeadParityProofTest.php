<?php

declare(strict_types=1);

use Capell\Core\Contracts\PublicRenderData;
use Capell\Core\Data\Rendering\FrontendRuntimeManifestData;
use Capell\Core\Enums\AiDisclosure;
use Capell\Core\Enums\Rendering\RenderingStrategyEnum;
use Capell\Core\Enums\SiteAiDisclosure;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Core\ThemeStudio\Contracts\ThemeRuntimeSettings;
use Capell\Frontend\Data\Assets\RenderedFrontendResourcesData;
use Capell\Frontend\Data\FrontendMediaHintData;
use Capell\Frontend\Data\PublicHeadData;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Enums\RenderHookScenario;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Capell\Frontend\Support\State\FrontendState;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Vite;
use Illuminate\View\View;

require_once dirname(__DIR__, 5) . '/tests/Support/FrontendViewFixture.php';

it('preserves the frozen public head for each visitor without queries or model payloads', function (bool $signedIn, bool $populated, bool $sections): void {
    if ($signedIn) {
        $visitor = new User(['name' => 'Private head visitor']);
        $this->actingAs($visitor);
        $this->assertAuthenticatedAs($visitor);
    } else {
        $this->assertGuest();
    }

    $page = frontendViewFixture()['page'];
    $state = resolve(FrontendState::class);
    $site = $state->site() ?? throw new RuntimeException('Expected a site in the public head fixture.');
    $language = new Language(['code' => 'en', 'locale' => 'en_GB']);
    $language->id = 1;

    $french = new Language(['code' => 'fr', 'locale' => 'fr_FR']);
    $french->id = 2;

    $domain = new SiteDomain(['domain' => 'example.test', 'scheme' => 'https', 'path' => '']);
    $englishUrl = new PageUrl(['url' => '/news', 'language_id' => 1, 'status' => true]);
    $englishUrl->setRelation('language', $language)->setRelation('siteDomain', $domain);
    $frenchUrl = new PageUrl(['url' => '/actualites', 'language_id' => 2, 'status' => true]);
    $frenchUrl->setRelation('language', $french)->setRelation('siteDomain', $domain);
    $page->setRelation('pageUrl', $englishUrl);
    $page->setRelation('pageUrls', new EloquentCollection($populated ? [$frenchUrl, $englishUrl] : [$englishUrl]));

    $translation = new Translation(['language_id' => 1, 'title' => 'News & events', 'meta' => $populated ? [
        'title' => 'Latest :site', 'description' => '<b>Public</b> description', 'keywords' => 'arts, events',
    ] : []]);
    $page->setRelation('translation', $translation);
    $page->setRelation('translations', new EloquentCollection([$translation, new Translation(['language_id' => 2])]));

    $site->language_id = 1;
    $site->setRelation('siteDomain', $domain);
    $site->meta = $populated ? [
        'icon' => 'icons/apple.png', 'favicon' => 'icons/favicon.ico', 'color' => '#123456',
        SiteAiDisclosure::META_KEY => SiteAiDisclosure::Marker->value,
        'meta_tags' => '<meta name="site-example" content="site" />',
        'editorial_notes' => 'Private head configuration',
    ] : [];
    $page->meta = $populated ? [
        AiDisclosure::PAGE_META_KEY => AiDisclosure::Assisted->value,
        'meta_tags' => '<meta name="page-example" content="page" />',
    ] : [];
    $theme = new Theme(['key' => 'parity', 'custom_css' => $populated ? '.public-demo { color: red; }' : '', 'meta' => $populated ? [
        'font_family' => 'Public Sans', 'font_heading_family' => 'Public Heading',
        'critical_asset' => 'resources/css/critical.css', 'assets_path' => 'build',
        'meta_tags' => '<meta name="theme-example" content="theme" />',
        'fonts' => [
            ['type' => 'url', 'url' => 'https://fonts.googleapis.com/css2?family=Public'],
            ['type' => 'url', 'url' => 'https://fonts.googleapis.com/css2?family=Public'],
            ['type' => 'url'],
            ['type' => 'local', 'name' => 'Public Local', 'files' => ['fonts/public.woff2', 'fonts/public.woff']],
        ],
    ] : []]);
    if ($populated) {
        Vite::shouldReceive('content')->twice()->with('resources/css/critical.css', 'build')->andReturn('.critical-example { display: grid; }');
    }

    $state->withLanguage($language)->withTheme($theme);
    $state->setFrontendData('runtimeManifest', FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly));
    $state->setFrontendData('renderedFrontendResources', new RenderedFrontendResourcesData($populated ? '<script src="/public-head.js"></script>' : '', '', []));
    $state->setFrontendData('mediaHints', $populated ? [new FrontendMediaHintData('/hero.jpg', mimeType: 'image/jpeg', imageSrcset: '/hero.jpg 640w', imageSizes: '100vw')] : []);

    resolve(RenderHookRegistry::class)->register(RenderHookLocation::HeadOpen, '<script>window.publicHeadOpen = true;</script>');
    resolve(RenderHookRegistry::class)->register(RenderHookLocation::HeadClose, '<meta name="close-example" content="close" />', scenario: RenderHookScenario::SeoMeta->value);

    $template = ($sections ? '@section("meta_title")<em>Section title</em>@endsection @section("meta")<meta name="section-example" content="section" />@endsection @push("styles")<style>.stack-example { display: block; }</style>@endpush ' : '')
        . '<x-capell::app.head :livewire-enabled="false" />';
    $payloads = [];
    view()->composer('capell::public.head', function (View $view) use (&$payloads): void {
        $inputs = $view->getData();
        expect($inputs['renderData'])->toBeInstanceOf(PublicRenderData::class)
            ->and($inputs['renderData'])->toBeInstanceOf(PublicHeadData::class);
        foreach ($inputs as $input) {
            expect($input)->not->toBeInstanceOf(Model::class);
        }

        foreach (get_object_vars($inputs['renderData']) as $value) {
            expect(publicHeadScalarTree($value))->toBeTrue();
        }

        $payloads[] = $inputs['renderData'];
    });

    // The request bootstrap normally hydrates the settings used by the theme head hook.
    resolve(ThemeRuntimeSettings::class)->activePreset();
    $connection = DB::connection();
    $connection->enableQueryLog();
    try {
        $actual = Blade::render($template);
        expect($payloads)->toHaveCount(1)
            ->and($connection->getQueryLog())->toBe([]);
    } finally {
        $connection->disableQueryLog();
    }

    $base = dirname(__DIR__, 2) . '/Fixtures/stage-three-base-public-views';
    view()->prependNamespace('capell', $base);
    view()->getFinder()->flush();
    expect(view()->getFinder()->find('capell::components.app.head.index'))->toBe($base . '/components/app/head/index.blade.php');
    $expected = Blade::render($template);
    expect(trim((string) preg_replace('/>\s+</', '><', $actual)))->toBe(trim((string) preg_replace('/>\s+</', '><', $expected)))
        ->and($actual)->not->toContain('Private head visitor')
        ->and($actual)->not->toContain('Private head configuration')
        ->and($actual)->not->toContain('data-capell-authoring')
        ->and($actual)->not->toContain('editorial_notes');

    $dom = frontendRenderedDom($actual);
    expect(domCount($dom, '//link[@rel="alternate"]'))->toBe($populated ? 3 : 0)
        ->and(domCount($dom, '//link[@rel="stylesheet"]'))->toBe($populated ? 1 : 0)
        ->and(domCount($dom, '//meta[@name="ai-disclosure"]'))->toBe($populated ? 1 : 0)
        ->and(domCount($dom, '//link[@rel="preload"]'))->toBe($populated ? 1 : 0);
})->with(['anonymous' => false, 'signed in' => true])->with(['minimal' => false, 'populated' => true])->with(['defaults' => false, 'sections and stacks' => true]);

function publicHeadScalarTree(mixed $value): bool
{
    if (is_array($value)) {
        return array_all($value, publicHeadScalarTree(...));
    }

    return $value === null || is_scalar($value);
}
