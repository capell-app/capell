<?php

declare(strict_types=1);

use Capell\Core\Actions\VisitUrlAction;
use Capell\Core\Events\UrlVisitFailed;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

it('rejects localhost URLs before issuing an HTTP request', function (): void {
    Http::fake();

    VisitUrlAction::run('http://127.0.0.1/private');

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

    VisitUrlAction::run('https://93.184.216.35/missing', $page->getKey());

    Event::assertDispatched(
        UrlVisitFailed::class,
        fn (UrlVisitFailed $event): bool => $event->url === 'https://93.184.216.35/missing'
            && $event->statusCode === 404
            && $event->pageId === $page->getKey(),
    );
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
