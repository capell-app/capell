<?php

declare(strict_types=1);

use Capell\Core\Actions\Activity\RecordActivityBucketAction;
use Capell\Core\Contracts\ActivitySettingsReader;
use Capell\Core\Models\ActivityBucket;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Http\Controllers\ActivityBeaconController;
use Capell\Frontend\Support\Http\CrawlerDetector;
use Capell\Frontend\Support\Routing\ReservedFrontendPathRegistry;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

function makeActivityBeaconControllerForTest(bool $enabled = true): ActivityBeaconController
{
    $settings = new readonly class($enabled) implements ActivitySettingsReader
    {
        public function __construct(private bool $enabled) {}

        public function collectionEnabled(): bool
        {
            return $this->enabled;
        }

        public function searchCollectionEnabled(): bool
        {
            return false;
        }

        public function retentionDays(): int
        {
            return 1;
        }
    };

    return new ActivityBeaconController($settings, resolve(RecordActivityBucketAction::class), new CrawlerDetector);
}

it('keeps the activity beacon sessionless and rejects non-page subjects silently', function (): void {
    $route = collect(resolve(Router::class)->getRoutes()->getRoutes())
        ->first(fn (Route $route): bool => $route->getName() === 'capell-frontend.activity');

    expect($route)->toBeInstanceOf(Route::class);
    throw_unless($route instanceof Route, RuntimeException::class, 'Activity route was not registered.');

    expect($route->gatherMiddleware())->not->toContain('web')
        ->and(resolve(ReservedFrontendPathRegistry::class)->isReserved('_capell/activity'))->toBeTrue()
        ->and(makeActivityBeaconControllerForTest()(Request::create('/_capell/activity', Symfony\Component\HttpFoundation\Request::METHOD_POST, ['type' => 'search_term']))->getStatusCode())->toBe(204);
});

it('ignores crawler and unknown page beacons without storage writes', function (): void {
    expect(makeActivityBeaconControllerForTest()(Request::create(
        '/_capell/activity',
        Symfony\Component\HttpFoundation\Request::METHOD_POST,
        ['type' => 'page_view', 'path' => '/unknown'],
        server: ['HTTP_USER_AGENT' => 'Googlebot/2.1'],
    ))->getStatusCode())->toBe(204)
        ->and(makeActivityBeaconControllerForTest()(Request::create(
            '/_capell/activity',
            Symfony\Component\HttpFoundation\Request::METHOD_POST,
            ['type' => 'page_view', 'path' => '/unknown'],
        ))->getStatusCode())->toBe(204);
});

it('honors browser privacy signals before resolving or recording a page', function (string $header): void {
    $request = Request::create('/_capell/activity', Symfony\Component\HttpFoundation\Request::METHOD_POST, [
        'type' => 'page_view',
        'path' => '/about',
    ], server: [$header => '1']);

    expect(makeActivityBeaconControllerForTest()($request)->getStatusCode())->toBe(204);
})->with(['HTTP_DNT', 'HTTP_SEC_GPC']);

it('rate limits beacon attempts without exposing the raw address in the key', function (): void {
    config(['capell.analytics.rate_limit_per_minute' => 1]);
    $request = Request::create('/_capell/activity', Symfony\Component\HttpFoundation\Request::METHOD_POST, [
        'type' => 'page_view',
        'path' => '/unknown',
    ], server: ['REMOTE_ADDR' => '203.0.113.10']);

    makeActivityBeaconControllerForTest()($request);
    makeActivityBeaconControllerForTest()($request);

    $key = 'capell-activity:' . hash_hmac('sha256', '203.0.113.10', (string) config('app.key'));

    expect(RateLimiter::attempts($key))->toBe(1)
        ->and(RateLimiter::attempts('capell-activity:203.0.113.10'))->toBe(0);
});

it('honors a page blueprint that disables visit logging', function (): void {
    $language = Language::factory()->createOne(['code' => 'en']);
    $site = Site::factory()->createOne(['language_id' => $language->getKey()]);
    SiteDomain::factory()->default()->site($site)->language($language)->create();
    $page = Page::factory()->site($site)->createOne();
    $page->blueprint->update(['meta' => ['disable_visit_logs' => true]]);
    PageUrl::factory()->page($page)->language($language)->site($site)->state(['url' => '/about'])->create();

    $request = Request::create('http://localhost/_capell/activity', Symfony\Component\HttpFoundation\Request::METHOD_POST, [
        'type' => 'page_view',
        'path' => '/about',
    ]);

    expect(makeActivityBeaconControllerForTest()($request)->getStatusCode())->toBe(204)
        ->and(ActivityBucket::query()->count())->toBe(0);
});
