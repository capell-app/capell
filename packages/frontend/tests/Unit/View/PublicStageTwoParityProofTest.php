<?php

declare(strict_types=1);

use Capell\Core\Contracts\PublicRenderData;
use Capell\Core\Models\Page;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
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

it('preserves the base public component HTML for each visitor', function (bool $signedIn, string $component, string $template): void {
    if ($signedIn) {
        $visitor = new User(['name' => 'Private visitor']);
        $this->actingAs($visitor);
        $this->assertAuthenticatedAs($visitor);
    } else {
        $this->assertGuest();
    }

    ['page' => $page, 'media' => $media] = frontendViewFixture();
    resolve(FrontendState::class)->withTheme(new Theme(['meta' => ['rounded_images' => true]]));
    $page->setRelation('image', $media);
    $page->setRelation('translation', new Translation(['title' => 'Arts & news', 'meta' => [
        'label' => 'Arts & culture', 'summary' => '<b>Local</b> news',
    ]]));
    $listPage = new Page;
    $listPage->setRawAttributes(['active' => false, 'label' => 'Pages', 'data' => null]);

    view()->addNamespace('parity', dirname(__DIR__, 2) . '/Fixtures/views');
    $item = (object) ['label' => 'Menu & links', 'active' => true, 'data' => ['url' => '/menu'], 'children' => [
        (object) ['label' => 'News', 'active' => true, 'data' => ['url' => '/news'], 'children' => []],
        (object) ['data' => ['component' => 'parity::list-child'], 'caption' => 'Custom & preserved'],
    ]];
    $date = Date::parse('2025-04-16T10:30:00+00:00');
    $author = (object) ['name' => 'Public & Co'];
    $unloaded = new Page;
    foreach ([RenderHookLocation::BeforeContent, RenderHookLocation::BeforeTitle, RenderHookLocation::AfterTitle, RenderHookLocation::Footer] as $location) {
        resolve(RenderHookRegistry::class)->register($location, '<i>' . $location->value . '</i>');
    }

    $render = static function () use ($template, $page, $unloaded, $listPage, $item, $date, $author, $media): string {
        $html = Blade::render($template, ['page' => $page, 'unloaded' => $unloaded, 'listPage' => $listPage, 'item' => $item, 'date' => $date, 'author' => $author, 'media' => $media]);

        return trim((string) preg_replace('/>\s+</', '><', $html));
    };

    $payloads = [];
    view()->composer('capell::public.*', function (View $view) use (&$payloads): void {
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
        expect($payloads)->not->toBeEmpty();
        expect($connection->getQueryLog())->toBe([]);
    } finally {
        $connection->disableQueryLog();
    }

    $base = dirname(__DIR__, 2) . '/Fixtures/base-public-views';
    view()->prependNamespace('capell', $base);
    view()->getFinder()->flush();
    expect(view()->getFinder()->find('capell::components.' . $component))->toStartWith($base . '/');
    expect($actual)->toBe($render())
        ->and($actual)->not->toContain('Private visitor')
        ->and($actual)->not->toContain('data-capell-authoring');
})->with(['anonymous' => false, 'signed in' => true])->with([
    'neighbor-previous' => ['page.neighbor-link', '<x-capell::page.neighbor-link class="external" neighbor="previous" :neighbor-page="$page" />'],
    'neighbor-next' => ['page.neighbor-link', '<x-capell::page.neighbor-link neighbor="next" :neighbor-page="$page" :with-summary="false" :with-image="false" />'],
    'neighbor-unloaded' => ['page.neighbor-link', '<x-capell::page.neighbor-link neighbor="next" :neighbor-page="$unloaded" />'],
    'unloaded list children' => ['list.item', '<x-capell::list.item :item="$listPage" />'],
    'list' => ['list.item', '<x-capell::list.item class="external" :item="$item" :active="false" />'],
    'next with image' => ['page.neighbor-link', '<x-capell::page.neighbor-link neighbor="next" :neighbor-page="$page" />'],
    'tile with no optional props' => ['asset.tile', '<x-capell::asset.tile title="Public tile" />'],
    'tile-top' => ['asset.tile', '<x-capell::asset.tile title="A &amp; B" summary="Public summary" :parent="$page" :publish-date="$date" :author="$author" publish-date-position="top" :image="$media" image-position="top" url="/article" link-text="Open" class="external" />'],
    'tile-right' => ['asset.tile', '<x-capell::asset.tile title="Article" :parent="$unloaded" :publish-date="$date" :author="$author" :image="$media" image-position="right" :square-image="true" />'],
]);

it('omits unhydrated page links without querying for a domain', function (): void {
    $page = frontendViewFixture()['page'];
    $page->pageUrl->unsetRelation('siteDomain');

    $connection = DB::connection();
    $connection->enableQueryLog();
    try {
        $neighbor = Blade::render('<x-capell::page.neighbor-link :neighbor-page="$page" neighbor="next" />', ['page' => $page]);
        $tile = Blade::render('<x-capell::asset.tile :parent="$page" title="Public tile" />', ['page' => $page]);
        expect(trim($neighbor))->toBe('')
            ->and(domCount(frontendRenderedDom($tile), '//a'))->toBe(0)
            ->and($connection->getQueryLog())->toBe([]);
    } finally {
        $connection->disableQueryLog();
    }
});

it('keeps the page asset model out of tile HTML attributes', function (): void {
    $page = frontendViewFixture()['page'];
    $page->meta = ['editorial' => 'Private draft notes'];

    $html = Blade::render('<x-capell::page.asset :asset="$page" :loop="null" component-item="capell.asset.tile" />', ['page' => $page]);
    $dom = frontendRenderedDom($html);

    expect(trim(domText(domElement($dom, '//h3/a'))))->toBe('Public title')
        ->and(domCount($dom, '//*[@asset or @ancestor]'))->toBe(0)
        ->and($html)->not->toContain('Private draft notes');
});
