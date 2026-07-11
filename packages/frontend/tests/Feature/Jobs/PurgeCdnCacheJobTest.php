<?php

declare(strict_types=1);

use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Frontend\Jobs\PurgeCdnCacheJob;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('dispatches a purge job when frontend surrogate keys are invalidated', function (): void {
    config(['capell-frontend.cdn_provider' => 'fastly']);

    Bus::fake([PurgeCdnCacheJob::class]);

    event(new FrontendSurrogateKeysInvalidated(['site-1', 'site-1', 'page-2']));

    Bus::assertDispatched(PurgeCdnCacheJob::class);
});

it('does not dispatch a purge job when frontend surrogate keys are invalidated without a configured provider', function (): void {
    config(['capell-frontend.cdn_provider' => null]);

    Bus::fake([PurgeCdnCacheJob::class]);

    event(new FrontendSurrogateKeysInvalidated(['site-1', 'page-2']));

    Bus::assertNotDispatched(PurgeCdnCacheJob::class);
});

// ---------------------------------------------------------------------------
// No provider configured
// ---------------------------------------------------------------------------

it('no-ops without throwing when no cdn provider is configured', function (): void {
    config(['capell-frontend.cdn_provider' => null]);

    Http::fake();

    dispatch_sync(new PurgeCdnCacheJob(['page-1', 'site-3']));

    Http::assertNothingSent();
});

it('logs an info message when no cdn provider is configured', function (): void {
    config(['capell-frontend.cdn_provider' => null]);

    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'no provider configured')
            && $context['keys'] === ['page-1', 'site-3']);

    // Suppress other log calls (info from success paths won't fire here)
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    dispatch_sync(new PurgeCdnCacheJob(['page-1', 'site-3']));
});

// ---------------------------------------------------------------------------
// Unknown provider
// ---------------------------------------------------------------------------

it('logs a warning for an unrecognised cdn provider', function (): void {
    config(['capell-frontend.cdn_provider' => 'acme-cdn']);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'Unknown CDN provider'));

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    Http::fake();

    dispatch_sync(new PurgeCdnCacheJob(['page-1']));

    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------
// Cloudflare — happy path
// ---------------------------------------------------------------------------

it('sends surrogate keys to the cloudflare purge endpoint', function (): void {
    config([
        'capell-frontend.cdn_provider' => 'cloudflare',
        'capell-frontend.cloudflare_purge_token' => 'tok-abc',
        'capell-frontend.cloudflare_zone_id' => 'zone-xyz',
    ]);

    Http::fake([
        'https://api.cloudflare.com/*' => Http::response(['success' => true], 200),
    ]);

    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Cloudflare CDN purge successful')
            && $context['keys'] === ['page-1', 'site-3']);

    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    dispatch_sync(new PurgeCdnCacheJob(['page-1', 'site-3']));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'zone-xyz/purge_cache')
            && $request['tags'] === ['page-1', 'site-3']
            && $request->hasHeader('Authorization', 'Bearer tok-abc'));
});

// ---------------------------------------------------------------------------
// Cloudflare — missing credentials
// ---------------------------------------------------------------------------

it('logs a warning and sends no request when cloudflare credentials are absent', function (): void {
    config([
        'capell-frontend.cdn_provider' => 'cloudflare',
        'capell-frontend.cloudflare_purge_token' => '',
        'capell-frontend.cloudflare_zone_id' => '',
    ]);

    Http::fake();

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'missing credentials'));

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    dispatch_sync(new PurgeCdnCacheJob(['page-1']));

    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------
// Cloudflare — HTTP failure re-throws so the job retries
// ---------------------------------------------------------------------------

it('logs an error and rethrows when the cloudflare api returns a failure', function (): void {
    config([
        'capell-frontend.cdn_provider' => 'cloudflare',
        'capell-frontend.cloudflare_purge_token' => 'tok-abc',
        'capell-frontend.cloudflare_zone_id' => 'zone-xyz',
    ]);

    Http::fake([
        'https://api.cloudflare.com/*' => Http::response('Server Error', 500),
    ]);

    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'Cloudflare CDN purge failed'));

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();

    expect(fn () => dispatch_sync(new PurgeCdnCacheJob(['page-1'])))->toThrow(Exception::class);
});

// ---------------------------------------------------------------------------
// Fastly — happy path
// ---------------------------------------------------------------------------

it('sends a purge request per surrogate key to the fastly api', function (): void {
    config([
        'capell-frontend.cdn_provider' => 'fastly',
        'capell-frontend.fastly_api_key' => 'fastly-key',
    ]);

    Http::fake([
        'https://api.fastly.com/*' => Http::response('', 200),
    ]);

    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Fastly CDN purge successful')
            && $context['keys'] === ['page-1', 'site-3']);

    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    dispatch_sync(new PurgeCdnCacheJob(['page-1', 'site-3']));

    // One request per key
    Http::assertSentCount(2);
});

it('drops invalid surrogate keys before sending fastly purge requests', function (): void {
    config([
        'capell-frontend.cdn_provider' => 'fastly',
        'capell-frontend.fastly_api_key' => 'fastly-key',
    ]);

    Http::fake([
        'https://api.fastly.com/*' => Http::response('', 200),
    ]);

    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Fastly CDN purge successful')
            && $context['keys'] === ['page-1']);

    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    dispatch_sync(new PurgeCdnCacheJob(['page-1', "bad\r\nHeader: injected", '../site-3', 'site/3']));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.fastly.com/purge/page-1');
});

it('no-ops when no valid surrogate keys remain', function (): void {
    config([
        'capell-frontend.cdn_provider' => 'fastly',
        'capell-frontend.fastly_api_key' => 'fastly-key',
    ]);

    Http::fake();

    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'no valid surrogate keys'));

    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    dispatch_sync(new PurgeCdnCacheJob(["bad\r\nHeader: injected", '../site-3']));

    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------
// Fastly — missing API key
// ---------------------------------------------------------------------------

it('logs a warning and sends no request when fastly api key is absent', function (): void {
    config([
        'capell-frontend.cdn_provider' => 'fastly',
        'capell-frontend.fastly_api_key' => '',
    ]);

    Http::fake();

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'missing API key'));

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    dispatch_sync(new PurgeCdnCacheJob(['page-1']));

    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------
// Varnish — happy path
// ---------------------------------------------------------------------------

it('sends a ban request with comma-joined surrogate keys to varnish', function (): void {
    config([
        'capell-frontend.cdn_provider' => 'varnish',
        'capell-frontend.varnish_url' => 'http://varnish.local',
    ]);

    Http::fake([
        'http://varnish.local' => Http::response('', 200),
    ]);

    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Varnish CDN purge successful')
            && $context['keys'] === ['page-1', 'site-3']);

    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    dispatch_sync(new PurgeCdnCacheJob(['page-1', 'site-3']));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Surrogate-Key', 'page-1,site-3'));
});

// ---------------------------------------------------------------------------
// Varnish — missing URL
// ---------------------------------------------------------------------------

it('logs a warning and sends no request when varnish url is absent', function (): void {
    config([
        'capell-frontend.cdn_provider' => 'varnish',
        'capell-frontend.varnish_url' => '',
    ]);

    Http::fake();

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'missing URL'));

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    dispatch_sync(new PurgeCdnCacheJob(['page-1']));

    Http::assertNothingSent();
});
