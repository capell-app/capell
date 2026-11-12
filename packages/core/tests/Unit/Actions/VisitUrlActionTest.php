<?php

declare(strict_types=1);

use Capell\Core\Actions\VisitUrlAction;
use Capell\Core\Contracts\Security\ResolvesHostAddresses;
use Capell\Core\Events\UrlVisitFailed;
use Capell\Core\Exceptions\UrlVisitFailedException;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

it('rejects localhost URLs before issuing an HTTP request', function (): void {
    Http::fake();

    expect(fn () => VisitUrlAction::run('http://127.0.0.1/private'))->toThrow(UrlVisitFailedException::class);

    Http::assertNothingSent();
});

it('allows registered public site domain URLs', function (): void {
    Http::fake([
        'https://93.184.216.34/public' => Http::response('', 200),
    ]);

    $language = Language::factory()->createOne();
    Site::factory()
        ->has(SiteDomain::factory()->language($language)->state([
            'domain' => '93.184.216.34',
            'scheme' => 'https',
            'status' => true,
        ]))
        ->create();

    VisitUrlAction::run('https://93.184.216.34/public');

    Http::assertSentCount(1);
});

it('allows app host URLs when a null site domain is registered', function (): void {
    config(['app.url' => 'https://93.184.216.34']);

    Http::fake([
        'https://93.184.216.34/public' => Http::response('', 200),
    ]);

    $language = Language::factory()->createOne();
    Site::factory()
        ->has(SiteDomain::factory()->language($language)->state([
            'domain' => null,
            'scheme' => 'https',
            'status' => true,
        ]))
        ->create();

    VisitUrlAction::run('https://93.184.216.34/public');

    Http::assertSentCount(1);
});

it('dispatches failed url visit events with page context', function (): void {
    Event::fake();
    Http::fake([
        'https://93.184.216.35/missing' => Http::response('', 404),
    ]);

    $language = Language::factory()->createOne();
    $site = Site::factory()
        ->has(SiteDomain::factory()->language($language)->state([
            'domain' => '93.184.216.35',
            'scheme' => 'https',
            'status' => true,
        ]))
        ->create();
    $page = Page::factory()->for($site)->create();

    expect(fn () => VisitUrlAction::run('https://93.184.216.35/missing', $page->getKey()))
        ->toThrow(UrlVisitFailedException::class, 'HTTP 404');

    Event::assertDispatched(
        UrlVisitFailed::class,
        fn (UrlVisitFailed $event): bool => $event->url === 'https://93.184.216.35/missing'
            && $event->statusCode === 404
            && $event->pageId === $page->getKey(),
    );
});

it('fails synchronous and queued visits for unsuccessful HTTP responses', function (int $status, bool $queued): void {
    SiteDomain::factory()->createOne(['domain' => '93.184.216.34', 'status' => true]);
    Http::fake(['https://93.184.216.34/required' => Http::response('', $status, ['Location' => 'https://127.0.0.1/private'])]);

    expect(function () use ($queued): void {
        if ($queued) {
            VisitUrlAction::dispatchSync('https://93.184.216.34/required');
        } else {
            VisitUrlAction::run('https://93.184.216.34/required');
        }
    })->toThrow(UrlVisitFailedException::class, 'HTTP ' . $status);

    Http::assertSentCount(1);
})->with([302, 404, 500])->with([false, true]);

it('fails rejected visits without delivering a request or exposing URL credentials', function (string $url, ?string $registeredHost): void {
    if ($registeredHost !== null) {
        SiteDomain::factory()->createOne(['domain' => $registeredHost, 'status' => true]);
    }

    Http::fake();

    try {
        VisitUrlAction::run($url);
        test()->fail('A rejected visit must fail its caller.');
    } catch (UrlVisitFailedException $urlVisitFailedException) {
        expect($urlVisitFailedException->getMessage())->not->toContain('secret')->not->toContain('password')->not->toContain('token=');
    }

    Http::assertNothingSent();
})->with([
    'scheme' => ['file:///secret', null],
    'unregistered host' => ['https://user:password@93.184.216.34/required?token=secret', null],
    'private address' => ['http://127.0.0.1/required?token=secret', '127.0.0.1'],
    'carrier-grade NAT address' => ['http://100.64.0.1/required?token=secret', '100.64.0.1'],
    'benchmarking address' => ['http://198.18.0.1/required?token=secret', '198.18.0.1'],
    'unresolved host' => ['https://unresolvable.invalid/required?token=secret', 'unresolvable.invalid'],
]);

it('propagates transport failures to visit callers', function (): void {
    SiteDomain::factory()->createOne(['domain' => '93.184.216.34', 'status' => true]);
    Http::fake(['*' => Http::failedConnection()]);

    expect(fn () => VisitUrlAction::run('https://93.184.216.34/required'))
        ->toThrow(ConnectionException::class);
});

it('checks a registered host name through the guarded resolver before visiting it', function (array $addresses, bool $sent): void {
    SiteDomain::factory()->createOne(['domain' => 'www.example.com', 'status' => true]);
    app()->instance(ResolvesHostAddresses::class, new readonly class($addresses) implements ResolvesHostAddresses
    {
        /** @param list<string> $addresses */
        public function __construct(private array $addresses) {}

        public function resolve(string $host): array
        {
            return $host === 'www.example.com' ? $this->addresses : [];
        }
    });
    Http::fake(['*' => Http::response('', 200)]);

    $visit = fn () => VisitUrlAction::run('https://www.example.com/public');

    if ($sent) {
        $visit();
        Http::assertSentCount(1);

        return;
    }

    expect($visit)->toThrow(UrlVisitFailedException::class);
    Http::assertNothingSent();
})->with([
    'public answer' => [['93.184.216.34'], true],
    'rebound to metadata' => [['169.254.169.254'], false],
    'one private answer among public ones' => [['93.184.216.34', '10.0.0.1'], false],
]);

it('refuses a registered public host on a port other than 80 or 443', function (): void {
    Http::fake();

    $language = Language::factory()->createOne();
    Site::factory()
        ->has(SiteDomain::factory()->language($language)->state([
            'domain' => '93.184.216.36',
            'scheme' => 'https',
            'status' => true,
        ]))
        ->create();

    expect(fn () => VisitUrlAction::run('https://93.184.216.36:6379/'))->toThrow(UrlVisitFailedException::class);

    Http::assertNothingSent();
});

it('names the refusal reason when a visit is stopped', function (string $url, string $key): void {
    Http::fake();
    SiteDomain::factory()->createOne(['domain' => '93.184.216.37', 'status' => true]);

    expect(fn () => VisitUrlAction::run($url))->toThrow(UrlVisitFailedException::class, __($key));
})->with([
    'custom port' => ['https://93.184.216.37:8080/', 'capell-core::message.visit_requires_standard_port'],
]);

it('stops a visit whose page is larger than the download limit and says so', function (): void {
    Http::fake(['*' => Http::response(str_repeat('a', 10 * 1024 * 1024 + 1))]);
    SiteDomain::factory()->createOne(['domain' => '93.184.216.38', 'status' => true]);

    expect(fn () => VisitUrlAction::run('https://93.184.216.38/large'))
        ->toThrow(UrlVisitFailedException::class, __('capell-core::message.visit_response_too_large'));
});
