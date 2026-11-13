<?php

declare(strict_types=1);

use Capell\Core\Actions\Activity\RecordActivityBucketAction;
use Capell\Core\Actions\Activity\RecordActivityVisitorAction;
use Capell\Core\Contracts\ActivitySettingsReader;
use Capell\Core\Contracts\Privacy\ConsentDecisionProvider;
use Capell\Core\Enums\ConsentDecision;
use Capell\Core\Models\ActivityBucket;
use Capell\Core\Models\ActivityVisitor;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Privacy\ConsentSettings;
use Capell\Frontend\Http\Controllers\ActivityBeaconController;
use Capell\Frontend\Support\Http\CrawlerDetector;
use Capell\Frontend\Support\Routing\ReservedFrontendPathRegistry;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;

function makeActivityBeaconControllerForTest(bool $enabled = true, ?ConsentDecisionProvider $consent = null): ActivityBeaconController
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

        public function visitorRetentionDays(): int
        {
            return 30;
        }
    };

    return new ActivityBeaconController(
        $settings,
        resolve(RecordActivityBucketAction::class),
        resolve(RecordActivityVisitorAction::class),
        new CrawlerDetector,
        $consent ?? resolve(ConsentDecisionProvider::class),
        resolve(ConsentSettings::class),
    );
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

it('records one visitor row alongside the page view and none when a gate refuses', function (): void {
    config(['capell.privacy.consent.defaults.analytics' => 'granted']);
    $language = Language::factory()->createOne(['code' => 'en']);
    $site = Site::factory()->createOne(['language_id' => $language->getKey()]);
    SiteDomain::factory()->default()->site($site)->language($language)->create();
    $page = Page::factory()->site($site)->createOne();
    PageUrl::factory()->page($page)->language($language)->site($site)->state(['url' => '/about'])->create();

    $payload = ['type' => 'page_view', 'path' => '/about'];
    $server = ['REMOTE_ADDR' => '203.0.113.20', 'HTTP_USER_AGENT' => 'Mozilla/5.0'];
    $request = Request::create('http://localhost/_capell/activity', Symfony\Component\HttpFoundation\Request::METHOD_POST, $payload, server: $server);

    makeActivityBeaconControllerForTest()($request);

    $visitor = ActivityVisitor::query()->sole();

    expect(ActivityVisitor::query()->count())->toBe(1)
        ->and($visitor->site_id)->toBe($site->getKey())
        ->and($visitor->language)->toBe('en')
        ->and($visitor->visitor_hash)->not->toContain('203.0.113.20');

    // Collection disabled and a privacy signal both stop short of the visitor write.
    makeActivityBeaconControllerForTest(false)(Request::create('http://localhost/_capell/activity', Symfony\Component\HttpFoundation\Request::METHOD_POST, $payload, server: $server + ['REMOTE_ADDR' => '203.0.113.21']));
    makeActivityBeaconControllerForTest()(Request::create('http://localhost/_capell/activity', Symfony\Component\HttpFoundation\Request::METHOD_POST, $payload, server: $server + ['REMOTE_ADDR' => '203.0.113.22', 'HTTP_DNT' => '1']));

    expect(ActivityVisitor::query()->count())->toBe(1);
});

function activityBeaconPageForTest(): void
{
    $language = Language::factory()->createOne(['code' => 'en']);
    $site = Site::factory()->createOne(['language_id' => $language->getKey()]);
    SiteDomain::factory()->default()->site($site)->language($language)->create();
    $page = Page::factory()->site($site)->createOne();
    PageUrl::factory()->page($page)->language($language)->site($site)->state(['url' => '/about'])->create();
}

function activityBeaconRequestForTest(?string $header = null): Request
{
    $server = ['REMOTE_ADDR' => '203.0.113.30', 'HTTP_USER_AGENT' => 'Mozilla/5.0'] + ($header === null ? [] : [$header => '1']);

    return Request::create('http://localhost/_capell/activity', Symfony\Component\HttpFoundation\Request::METHOD_POST, ['type' => 'page_view', 'path' => '/about'], server: $server);
}

it('counts and identifies visitors according to the analytics decision', function (string $decision, bool $counter, ?string $header, bool $counted, bool $visitor): void {
    config([
        'capell.privacy.consent.defaults.analytics' => $decision,
        'capell.privacy.first_party_counter' => $counter,
    ]);
    activityBeaconPageForTest();

    expect(makeActivityBeaconControllerForTest()(activityBeaconRequestForTest($header))->getStatusCode())->toBe(204)
        ->and(ActivityBucket::query()->exists())->toBe($counted)
        ->and(ActivityVisitor::query()->exists())->toBe($visitor);
})->with([
    'granted' => ['granted', true, null, true, true],
    'granted without the counter' => ['granted', false, null, true, true],
    'granted with Global Privacy Control' => ['granted', true, 'HTTP_SEC_GPC', false, false],
    'granted with Do Not Track' => ['granted', true, 'HTTP_DNT', false, false],
    'undecided counts without identifying' => ['undecided', true, null, true, false],
    'undecided without the counter' => ['undecided', false, null, false, false],
    'undecided with Global Privacy Control' => ['undecided', true, 'HTTP_SEC_GPC', false, false],
    'denied' => ['denied', true, null, false, false],
]);

it('treats a consent provider that fails as a refusal', function (): void {
    activityBeaconPageForTest();
    $failing = new class implements ConsentDecisionProvider
    {
        public function categories(): array
        {
            return [];
        }

        public function purposes(): array
        {
            return [];
        }

        public function decide(string $purposeOrCategory, Request $request): ConsentDecision
        {
            throw new RuntimeException('Consent store unavailable.');
        }
    };
    $reported = [];
    resolve(ExceptionHandler::class)->reportable(function (RuntimeException $exception) use (&$reported): bool {
        $reported[] = $exception->getMessage();

        return false;
    });

    expect(makeActivityBeaconControllerForTest(consent: $failing)(activityBeaconRequestForTest())->getStatusCode())->toBe(204)
        ->and(ActivityBucket::query()->exists())->toBeFalse()
        ->and(ActivityVisitor::query()->exists())->toBeFalse()
        ->and($reported)->toBe(['Consent store unavailable.']);
});
