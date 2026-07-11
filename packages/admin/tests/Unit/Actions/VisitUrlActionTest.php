<?php

declare(strict_types=1);

use Capell\Core\Actions\VisitUrlAction;
use Capell\Core\Models\SiteDomain;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('visits the provided url and does not log on success', function (): void {
    SiteDomain::factory()->createOne(['domain' => '93.184.216.34', 'status' => true]);

    Http::fake([
        'https://93.184.216.34' => Http::response('OK', 200),
    ]);
    Log::spy();

    VisitUrlAction::run('https://93.184.216.34');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://93.184.216.34');

    Log::shouldNotHaveReceived('info');
});

it('logs when visiting a url returns a non-OK response', function (): void {
    SiteDomain::factory()->createOne(['domain' => '93.184.216.35', 'status' => true]);

    Http::fake([
        'https://93.184.216.35' => Http::response('Not found', 404),
    ]);
    Log::spy();

    VisitUrlAction::run('https://93.184.216.35');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://93.184.216.35');

    Log::shouldHaveReceived('info')->once()->with('Problem accessing url', ['url' => 'https://93.184.216.35', 'status' => 404]);
});

it('does not throw for invalid url, but does not log info', function (): void {
    Http::fake();
    Log::spy();

    VisitUrlAction::run('not-a-domain');

    Http::assertNothingSent();
    Log::shouldNotHaveReceived('info');
});

it('rejects urls with disallowed schemes', function (): void {
    Http::fake();
    Log::spy();

    VisitUrlAction::run('file:///etc/passwd');

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once()->with('VisitUrlAction: rejected non-http(s) url', ['url' => 'file:///etc/passwd', 'scheme' => 'file']);
});

it('rejects urls with no scheme', function (): void {
    Http::fake();
    Log::spy();

    VisitUrlAction::run('not-a-url');

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once();
});

it('applies a connect and request timeout', function (): void {
    SiteDomain::factory()->createOne(['domain' => '93.184.216.34', 'status' => true]);

    Http::fake(fn () => Http::response('', 200));

    VisitUrlAction::run('https://93.184.216.34');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://93.184.216.34');
});
