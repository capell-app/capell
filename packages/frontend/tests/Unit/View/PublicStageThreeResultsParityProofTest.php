<?php

declare(strict_types=1);

use Capell\Core\Contracts\PublicRenderData;
use Capell\Core\Data\RenderableDefinitionData;
use Capell\Core\Data\Rendering\RenderHookContext;
use Capell\Core\Enums\RenderableTypeEnum;
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Renderables\RenderableRegistry;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

require_once dirname(__DIR__, 5) . '/tests/Support/FrontendViewFixture.php';

it('preserves page asset and results HTML behind isolated public data', function (bool $signedIn, string $component, string $template, string $resultKind): void {
    if ($signedIn) {
        $visitor = new User(['name' => 'Private visitor']);
        $this->actingAs($visitor);
        $this->assertAuthenticatedAs($visitor);
    } else {
        $this->assertGuest();
    }

    ['page' => $page, 'media' => $media] = frontendViewFixture();
    $page->setRawAttributes(['id' => 37, 'created_at' => '2025-04-16 10:30:00', 'children_count' => 4]);
    $page->meta = ['square_image' => true, 'editorial' => 'Private draft notes'];
    $page->setRelation('translation', new Translation(['title' => 'Arts & news', 'meta' => [
        'label' => 'Arts & culture', 'summary' => 'Local & public', 'link_text' => 'Explore & read', 'publish_date_position' => 'bottom',
    ]]));
    $page->setRelation('image', $media);
    $page->setRelation('creator', new User(['name' => 'Public & Co']));

    $parent = clone $page;
    $parent->setRelation('translation', new Translation(['title' => 'Parent page', 'meta' => ['label' => 'Parent label']]));

    $page->setRelation('parent', $parent);
    $page->setRelation('ancestors', new EloquentCollection([$page]));

    $other = clone $page;
    $other->setRelation('translation', new Translation(['title' => 'Second public result', 'meta' => ['summary' => 'Second summary']]));

    $unloaded = new Page;
    $request = Request::create('/articles/2');
    $route = Route::get('/articles/{page?}', static fn (): string => '')->name('stage-three-results-fixture');
    $route->bind($request);

    $request->setRouteResolver(static fn () => $route);
    app()->instance('request', $request);
    Route::dispatch($request);
    $results = match ($resultKind) {
        'null' => null,
        'empty' => collect(),
        'empty-paginator' => new LengthAwarePaginator([], 25, 10, 4, ['path' => '/articles']),
        'simple-paginator' => new Paginator([$page, $other], 2, 1, ['path' => '/articles']),
        'paginator' => new LengthAwarePaginator([$page, $other], 25, 10, 2, ['path' => '/articles']),
        default => collect([$page, $other]),
    };

    view()->addNamespace('parity', dirname(__DIR__, 2) . '/Fixtures/views');
    resolve(RenderableRegistry::class)->register(new RenderableDefinitionData('parity.result', RenderableTypeEnum::Asset, blade: 'parity::stage-three-result-item'));
    $hookItems = [];
    foreach ([RenderHookLocation::BeforeResult, RenderHookLocation::AfterResult] as $location) {
        resolve(RenderHookRegistry::class)->registerCallable($location, function (RenderHookContext $context) use (&$hookItems, $location): string {
            $hookItems[] = $context->item;

            return '<i>' . $location->value . '</i>';
        });
    }

    $render = static function () use ($template, $page, $unloaded, $results): string {
        $html = Blade::render($template, ['page' => $page, 'unloaded' => $unloaded, 'results' => $results]);

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
        expect($payloads)->not->toBeEmpty()
            ->and($connection->getQueryLog())->toBe([]);
        if ($component === 'page.results' && in_array($resultKind, ['collection', 'paginator', 'simple-paginator'], true)) {
            expect($hookItems)->toBe([$page, $page, $other, $other]);
        }

        $base = dirname(__DIR__, 2) . '/Fixtures/stage-three-base-public-views';
        view()->prependNamespace('capell', $base);
        view()->getFinder()->flush();
        expect(view()->getFinder()->find('capell::components.' . $component))->toStartWith($base . '/');
        expect($actual)->toBe($render())
            ->and($connection->getQueryLog())->toBe([])
            ->and($actual)->not->toContain('Private visitor')
            ->and($actual)->not->toContain('Private draft notes')
            ->and($actual)->not->toContain('data-capell-authoring');
    } finally {
        $connection->disableQueryLog();
    }
})->with(['anonymous' => false, 'signed in' => true])->with([
    'asset full custom component' => ['page.asset', '<x-capell::page.asset class="external" :asset="$page" :loop="null" component-item="parity.result" size="large" heading-size="h2" image-position="right" :with-author="true" :with-child-count="true" :with-link-text="true" :with-image="true" :with-parent="true" :with-date="true" :with-summary="true" />', 'collection'],
    'asset empty optional props' => ['page.asset', '<x-capell::page.asset :asset="$unloaded" :loop="null" component-item="capell.asset.tile" heading-size="h3" />', 'empty'],
    'asset built-in tile' => ['page.asset', '<x-capell::page.asset :asset="$page" :loop="null" component-item="capell.asset.tile" heading-size="h3" :with-image="true" :with-date="true" :with-parent="true" :with-summary="true" />', 'collection'],
    'results custom component' => ['page.results', '<x-capell::page.results class="external" :$results component-item="parity.result" :with-parent="true" :wire-children-count="true" />', 'collection'],
    'results built-in tile' => ['page.results', '<x-capell::page.results :$results component-item="capell.asset.tile" />', 'collection'],
    'results simple paginator' => ['page.results', '<x-capell::page.results :$results component-item="parity.result" />', 'simple-paginator'],
    'results paginator' => ['page.results', '<x-capell::page.results :$results component-item="parity.result" :wire-links="false" />', 'paginator'],
    'results wire paginator' => ['page.results', '<x-capell::page.results :$results component-item="parity.result" />', 'paginator'],
    'results empty collection' => ['page.results', '<x-capell::page.results :$results no-results-text="No &lt;b&gt;public&lt;/b&gt; results" />', 'empty'],
    'results null' => ['page.results', '<x-capell::page.results :$results />', 'null'],
    'results empty current page' => ['page.results', '<x-capell::page.results :$results :wire-links="false" />', 'empty-paginator'],
]);

it('omits unhydrated result URLs without querying for a domain', function (): void {
    $page = frontendViewFixture()['page'];
    $page->pageUrl->unsetRelation('siteDomain');

    $connection = DB::connection();
    $connection->enableQueryLog();
    try {
        $asset = Blade::render('<x-capell::page.asset :asset="$page" :loop="null" component-item="capell.asset.tile" heading-size="h3" />', ['page' => $page]);
        $results = Blade::render('<x-capell::page.results :results="$results" component-item="capell.asset.tile" />', ['results' => collect([$page])]);
        expect(domCount(frontendRenderedDom($asset), '//a'))->toBe(0)
            ->and(domCount(frontendRenderedDom($results), '//a'))->toBe(0)
            ->and($connection->getQueryLog())->toBe([]);
    } finally {
        $connection->disableQueryLog();
    }
});

it('preserves result hook timing and prepares following items only when reached', function (): void {
    view()->addNamespace('parity', dirname(__DIR__, 2) . '/Fixtures/views');
    resolve(RenderableRegistry::class)->register(new RenderableDefinitionData('parity.result', RenderableTypeEnum::Asset, blade: 'parity::stage-three-result-item'));
    $following = new Page;
    resolve(RenderHookRegistry::class)->registerCallable(RenderHookLocation::BeforeResult, function (RenderHookContext $context) use (&$following): string {
        if ($context->item->id === 1) {
            $context->item->children_count = 9;
            $context->item->created_at = '2025-05-17 10:30:00';
            $context->item->translation->meta = ['summary' => 'Changed first summary'];
            $context->item->pageUrl->url = '/changed-after-hook';
            $following->setRelation('translation', new Translation(['title' => 'Changed following result']));
        }

        return '<i>Before result</i>';
    });
    $render = static function () use (&$following): string {
        $page = frontendViewFixture()['page'];
        $page->setRawAttributes(['id' => 1, 'created_at' => '2025-04-16 10:30:00', 'children_count' => 2]);

        $following = clone $page;
        $following->id = 2;
        $following->setRelation('translation', new Translation(['title' => 'Original following result']));

        $html = Blade::render('<x-capell::page.results :$results component-item="parity.result" :wire-children-count="true" />', ['results' => collect([$page, $following])]);

        return trim((string) preg_replace('/>\s+</', '><', $html));
    };

    $connection = DB::connection();
    $connection->enableQueryLog();
    try {
        $actual = $render();
        expect($actual)->toContain('Changed first summary')
            ->and($actual)->toContain('Changed following result')
            ->and($actual)->toContain('2025-05-17')
            ->and(trim(domText(domElement(frontendRenderedDom($actual), '//article[1]/span[2]'))))->toBe('9')
            ->and(domCount(frontendRenderedDom($actual), '//a[@href="https://example.test/changed-after-hook"]'))->toBeGreaterThan(0);
        $base = dirname(__DIR__, 2) . '/Fixtures/stage-three-base-public-views';
        view()->prependNamespace('capell', $base);
        view()->getFinder()->flush();
        expect($actual)->toBe($render())
            ->and($connection->getQueryLog())->toBe([]);
    } finally {
        $connection->disableQueryLog();
    }
});
