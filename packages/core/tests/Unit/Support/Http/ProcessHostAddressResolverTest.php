<?php

declare(strict_types=1);

use Capell\Core\Contracts\Security\ResolvesHostAddresses;
use Capell\Core\Exceptions\OutboundRequestRefusedException;
use Capell\Core\Support\Http\OutboundHttp;
use Capell\Core\Support\Http\OutboundHttpPolicy;
use Capell\Core\Support\Http\ProcessHostAddressResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function stalledHostResolver(): ProcessHostAddressResolver
{
    return new ProcessHostAddressResolver(dirname(__DIR__, 3) . '/fixtures/dns/stalled-resolver.php');
}

it('resolves a host through the child process', function (): void {
    expect((new ProcessHostAddressResolver)->resolveWithin('localhost', 5.0))->toContain('127.0.0.1');
});

it('gives up on a lookup that outlives its deadline', function (): void {
    // The stalled child answers with a public address after 20 seconds, so an
    // unbounded wait would return it; the deadline must return nothing instead.
    expect(stalledHostResolver()->resolveWithin('stalled.example.test', 1.0))->toBe([]);
});

it('refuses a guarded request whose DNS lookup stalls past the connect timeout', function (): void {
    app()->instance(ResolvesHostAddresses::class, stalledHostResolver());
    Http::fake();

    expect(fn () => OutboundHttp::guarded(new OutboundHttpPolicy(connectTimeoutSeconds: 1))->get('https://stalled.example.test/'))
        ->toThrow(OutboundRequestRefusedException::class, 'could not be resolved');

    Http::assertNothingSent();
});

it('is the resolver guarded requests use by default', function (): void {
    expect(resolve(ResolvesHostAddresses::class))->toBeInstanceOf(ProcessHostAddressResolver::class);
});

it('says once that lookups are unbounded when no child process can be started', function (): void {
    $log = Log::spy();
    $resolver = new ProcessHostAddressResolver(childProcessesAllowed: false);

    $first = $resolver->resolveWithin('localhost', 1.0);
    $resolver->resolveWithin('localhost', 1.0);

    expect($first)->toContain('127.0.0.1');
    $log->shouldHaveReceived('warning')->once()->withArgs(static fn (string $message): bool => str_contains($message, 'without a deadline'));
});
