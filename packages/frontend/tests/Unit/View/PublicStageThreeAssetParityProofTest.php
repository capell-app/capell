<?php

declare(strict_types=1);

use Capell\Core\Contracts\PublicRenderData;
use Capell\Core\Data\RenderableDefinitionData;
use Capell\Core\Enums\RenderableTypeEnum;
use Capell\Core\Models\Page;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Core\Settings\CoreSettings;
use Capell\Core\Support\Renderables\RenderableRegistry;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Capell\Frontend\Support\State\FrontendState;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

require_once dirname(__DIR__, 5) . '/tests/Support/FrontendViewFixture.php';

it('preserves the frozen asset HTML for anonymous and signed-in visitors', function (bool $signedIn, string $component, string $template): void {
    if ($signedIn) {
        $visitor = new User(['name' => 'Private visitor']);
        $this->actingAs($visitor);
        $this->assertAuthenticatedAs($visitor);
    } else {
        $this->assertGuest();
    }

    ['page' => $page, 'media' => $media] = frontendViewFixture();
    // Supply the hydrated image policy settings before measuring public rendering.
    $settings = Mockery::mock(CoreSettings::class);
    $settings->allowed_remote_image_domains = ['example.test'];
    $settings->allow_relative_image_urls = true;

    app()->instance(CoreSettings::class, $settings);
    resolve(FrontendState::class)->withTheme(new Theme(['meta' => ['rounded_images' => true, 'list_bullets' => true]]));
    $page->setRelation('translation', new Translation(['title' => 'Parent title', 'meta' => ['label' => 'Arts & culture']]));
    $page->setRelation('image', $media);

    $unloaded = new Page;
    $date = Date::parse('2025-04-16T10:30:00+00:00');
    $author = (object) ['name' => 'Public & Co'];
    foreach ([RenderHookLocation::BeforeTitle, RenderHookLocation::AfterTitle, RenderHookLocation::Footer] as $location) {
        resolve(RenderHookRegistry::class)->register($location, '<i>' . $location->value . '</i>');
    }

    view()->addNamespace('asset-parity', dirname(__DIR__, 2) . '/Fixtures/views');
    resolve(RenderableRegistry::class)->register(new RenderableDefinitionData(
        key: 'asset-parity.custom',
        type: RenderableTypeEnum::Asset,
        blade: 'asset-parity::asset-parity-child',
    ));
    $render = static function () use ($template, $page, $media, $unloaded, $date, $author): string {
        $html = Blade::render($template, ['page' => $page, 'media' => $media, 'unloaded' => $unloaded, 'date' => $date, 'author' => $author]);

        return trim((string) preg_replace('/>\s+</', '><', $html));
    };
    $payloads = [];
    view()->composer(['capell::public.asset-index', 'capell::public.media-asset'], function (View $view) use (&$payloads): void {
        $inputs = $view->getData();
        expect($inputs['renderData'])->toBeInstanceOf(PublicRenderData::class);
        foreach ([...$inputs, ...$inputs['attributes']->all()] as $input) {
            expect($input)->not->toBeInstanceOf(Model::class);
        }

        foreach (get_object_vars($inputs['renderData']) as $value) {
            expect($value === null || is_scalar($value))->toBeTrue();
        }

        $payloads[] = $inputs['renderData'];
    });

    $connection = DB::connection();
    $connection->enableQueryLog();
    try {
        $actual = $render();
        if (str_contains($template, 'https://example.test/image.jpg')) {
            expect(domAttribute(domElement(frontendRenderedDom($actual), '//img'), 'src'))->toBe('https://example.test/image.jpg');
        }

        expect($payloads)->not->toBeEmpty()
            ->and($connection->getQueryLog())->toBe([]);
    } finally {
        $connection->disableQueryLog();
    }

    $base = dirname(__DIR__, 2) . '/Fixtures/stage-three-base-public-views';
    view()->prependNamespace('capell', $base);
    view()->getFinder()->flush();
    expect(view()->getFinder()->find('capell::components.' . $component))->toStartWith($base . '/');
    expect($actual)->toBe($render())
        ->and($actual)->not->toContain('Private visitor')
        ->and($actual)->not->toContain('data-capell-authoring');
})->with(['anonymous' => false, 'signed in' => true])->with([
    'empty index' => ['asset.index', '<x-capell::asset.index />'],
    'index title' => ['asset.index', '<x-capell::asset.index title="Public title" role="article" class="external" data-secret="Private visitor" />'],
    'index full top' => ['asset.index', '<x-capell::asset.index title="A &amp; B" summary="Public &amp; summary" :with-summary="true" :parent="$page" :publish-date="$date" :author="$author" publish-date-position="top" :image="$media" url="/article" link-text="Open" heading-size="h2" count="12" icon="heroicon-o-star" size="lg" :title-balance="true" />'],
    'index blog bottom' => ['asset.index', '<x-capell::asset.index title="Article" :image="$media" :square-image="true" :parent="$page" :publish-date="$date" :author="$author" class="capell-blog-article-card external" url="/article" link-text="Open" size="sm" :has-bullet="false" />'],
    'index no link' => ['asset.index', '<x-capell::asset.index title="Article" :image="$media" :parent="$unloaded" :publish-date="$date" heading-size="h3" icon="heroicon-o-star" size="md" />'],
    'index source image' => ['asset.index', '<x-capell::asset.index title="Remote" image="https://example.test/image.jpg" />'],
    'index structured image' => ['asset.index', '<x-capell::asset.index title="Structured" :image="[\'type\' => \'url\', \'url\' => \'https://example.test/image.jpg\']" />'],
    'media fallback relation' => ['media.asset', '<x-capell::media.asset :asset="$page->unsetRelation(\'image\')" :loop="null" />'],
    'media direct' => ['media.asset', '<x-capell::media.asset :asset="$media" :loop="null" />'],
    'media image relation' => ['media.asset', '<x-capell::media.asset :asset="$page" :loop="null" />'],
    'media unloaded' => ['media.asset', '<x-capell::media.asset :asset="$unloaded" :loop="null" />'],
    'media custom component' => ['media.asset', '<x-capell::media.asset :asset="$media" :loop="(object) [\'iteration\' => 4]" component-item="asset-parity.custom" />'],
]);

it('does not query for an unhydrated parent domain in an index card', function (): void {
    $page = frontendViewFixture()['page'];
    $page->setRelation('translation', new Translation(['meta' => ['label' => 'Parent']]));
    $page->pageUrl->unsetRelation('siteDomain');

    $connection = DB::connection();
    $connection->enableQueryLog();
    try {
        $html = Blade::render('<x-capell::asset.index title="Public title" :parent="$page" />', ['page' => $page]);
        expect(domCount(frontendRenderedDom($html), '//a'))->toBe(0)
            ->and($connection->getQueryLog())->toBe([]);
    } finally {
        $connection->disableQueryLog();
    }
});
