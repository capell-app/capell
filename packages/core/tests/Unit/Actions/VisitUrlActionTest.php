<?php

declare(strict_types=1);

use Capell\Core\Actions\VisitUrlAction;
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
        expect($urlVisitFailedException->getMessage())->not->toContain('secret', 'password', 'token=');
    }

    Http::assertNothingSent();
})->with([
    'scheme' => ['file:///secret', null],
    'unregistered host' => ['https://user:password@93.184.216.34/required?token=secret', null],
    'private address' => ['http://127.0.0.1/required?token=secret', '127.0.0.1'],
    'unresolved host' => ['https://unresolvable.invalid/required?token=secret', 'unresolvable.invalid'],
]);

it('propagates transport failures to visit callers', function (): void {
    SiteDomain::factory()->createOne(['domain' => '93.184.216.34', 'status' => true]);
    Http::fake(['*' => Http::failedConnection()]);

    expect(fn () => VisitUrlAction::run('https://93.184.216.34/required'))
        ->toThrow(ConnectionException::class);
});

it('pins the vetted address for registered host fetches', function (): void {
    $action = new VisitUrlAction;
    $method = new ReflectionMethod($action, 'pinnedDnsOptions');

    $options = $method->invoke($action, 'https://example.com/public', 'example.com', '93.184.216.34');

    expect($options)->toBe([
        'curl' => [
            CURLOPT_RESOLVE => ['example.com:443:93.184.216.34'],
        ],
    ]);
});
