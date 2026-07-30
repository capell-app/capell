<?php

declare(strict_types=1);

use Capell\Core\Support\Http\OutboundHttpRetry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Record every delay the retry policy asks the framework to sleep for, in milliseconds.
 *
 * @return list<int>
 */
function recordedRetryDelaysMs(Closure $callback): array
{
    $recordedDelaysMs = [];

    Sleep::fake();
    Sleep::whenFakingSleep(function (CarbonInterval $duration) use (&$recordedDelaysMs): void {
        $recordedDelaysMs[] = (int) round($duration->totalMilliseconds);
    });

    try {
        $callback();
    } finally {
        Sleep::fake(false);
    }

    return $recordedDelaysMs;
}

it('exhausts the configured attempt count and returns the final failed response without throwing', function (): void {
    Http::fake([
        'https://upstream.test/*' => Http::sequence()
            ->push(['error' => 'rate limited'], 429)
            ->push(['error' => 'rate limited'], 429)
            ->push(['error' => 'rate limited'], 429),
    ]);

    $response = null;

    $recordedDelaysMs = recordedRetryDelaysMs(function () use (&$response): void {
        $response = OutboundHttpRetry::make(retryTimes: 3, retryDelayMs: 250)
            ->apply(Http::acceptJson())
            ->get('https://upstream.test/resource');
    });

    expect($response?->status())->toBe(429)
        ->and($recordedDelaysMs)->toBe([250, 250]);

    Http::assertSentCount(3);
});

it('returns the first successful response and stops retrying', function (): void {
    Http::fake([
        'https://upstream.test/*' => Http::sequence()
            ->push(['error' => 'rate limited'], 503)
            ->push(['ok' => true], 200),
    ]);

    $response = null;

    $recordedDelaysMs = recordedRetryDelaysMs(function () use (&$response): void {
        $response = OutboundHttpRetry::make(retryTimes: 3, retryDelayMs: 250)
            ->apply(Http::acceptJson())
            ->get('https://upstream.test/resource');
    });

    expect($response?->status())->toBe(200)
        ->and($response?->json('ok'))->toBeTrue()
        ->and($recordedDelaysMs)->toBe([250]);

    Http::assertSentCount(2);
});

it('honours a Retry-After header given as a delay in seconds', function (): void {
    Http::fake([
        'https://upstream.test/*' => Http::sequence()
            ->push(['error' => 'rate limited'], 429, ['Retry-After' => '2'])
            ->push(['ok' => true], 200),
    ]);

    $recordedDelaysMs = recordedRetryDelaysMs(function (): void {
        OutboundHttpRetry::make(retryTimes: 2, retryDelayMs: 250, retryAfterMaxMs: 60000)
            ->apply(Http::acceptJson())
            ->get('https://upstream.test/resource');
    });

    expect($recordedDelaysMs)->toBe([2000]);
});

it('honours a Retry-After header given as an HTTP date', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-30 12:00:00', 'UTC'));

    Http::fake([
        'https://upstream.test/*' => Http::sequence()
            ->push(['error' => 'unavailable'], 503, ['Retry-After' => 'Thu, 30 Jul 2026 12:00:07 GMT'])
            ->push(['ok' => true], 200),
    ]);

    $recordedDelaysMs = recordedRetryDelaysMs(function (): void {
        OutboundHttpRetry::make(retryTimes: 2, retryDelayMs: 250, retryAfterMaxMs: 60000)
            ->apply(Http::acceptJson())
            ->get('https://upstream.test/resource');
    });

    expect($recordedDelaysMs)->toBe([7000]);

    CarbonImmutable::setTestNow();
});

it('treats a Retry-After date already in the past as no delay', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-30 12:00:00', 'UTC'));

    Http::fake([
        'https://upstream.test/*' => Http::sequence()
            ->push(['error' => 'unavailable'], 503, ['Retry-After' => 'Thu, 30 Jul 2026 11:59:00 GMT'])
            ->push(['ok' => true], 200),
    ]);

    $recordedDelaysMs = recordedRetryDelaysMs(function (): void {
        OutboundHttpRetry::make(retryTimes: 2, retryDelayMs: 250)
            ->apply(Http::acceptJson())
            ->get('https://upstream.test/resource');
    });

    expect($recordedDelaysMs)->toBe([0]);

    CarbonImmutable::setTestNow();
});

it('caps an excessive Retry-After delay in seconds at the configured maximum', function (): void {
    Http::fake([
        'https://upstream.test/*' => Http::sequence()
            ->push(['error' => 'rate limited'], 429, ['Retry-After' => '3600'])
            ->push(['ok' => true], 200),
    ]);

    $recordedDelaysMs = recordedRetryDelaysMs(function (): void {
        OutboundHttpRetry::make(retryTimes: 2, retryDelayMs: 250, retryAfterMaxMs: 1500)
            ->apply(Http::acceptJson())
            ->get('https://upstream.test/resource');
    });

    expect($recordedDelaysMs)->toBe([1500]);
});

it('caps an excessive Retry-After delay given as an HTTP date at the configured maximum', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-30 12:00:00', 'UTC'));

    Http::fake([
        'https://upstream.test/*' => Http::sequence()
            ->push(['error' => 'unavailable'], 503, ['Retry-After' => 'Fri, 31 Jul 2026 12:00:00 GMT'])
            ->push(['ok' => true], 200),
    ]);

    $recordedDelaysMs = recordedRetryDelaysMs(function (): void {
        OutboundHttpRetry::make(retryTimes: 2, retryDelayMs: 250, retryAfterMaxMs: 2000)
            ->apply(Http::acceptJson())
            ->get('https://upstream.test/resource');
    });

    expect($recordedDelaysMs)->toBe([2000]);

    CarbonImmutable::setTestNow();
});

it('falls back to the configured delay when Retry-After is unparseable', function (): void {
    Http::fake([
        'https://upstream.test/*' => Http::sequence()
            ->push(['error' => 'rate limited'], 429, ['Retry-After' => 'not-a-date'])
            ->push(['ok' => true], 200),
    ]);

    $recordedDelaysMs = recordedRetryDelaysMs(function (): void {
        OutboundHttpRetry::make(retryTimes: 2, retryDelayMs: 250, retryAfterMaxMs: 60000)
            ->apply(Http::acceptJson())
            ->get('https://upstream.test/resource');
    });

    expect($recordedDelaysMs)->toBe([250]);
});

it('falls back to the configured delay when Retry-After is absent or blank', function (): void {
    Http::fake([
        'https://upstream.test/*' => Http::sequence()
            ->push(['error' => 'rate limited'], 429, ['Retry-After' => '   '])
            ->push(['error' => 'rate limited'], 429)
            ->push(['ok' => true], 200),
    ]);

    $recordedDelaysMs = recordedRetryDelaysMs(function (): void {
        OutboundHttpRetry::make(retryTimes: 3, retryDelayMs: 250)
            ->apply(Http::acceptJson())
            ->get('https://upstream.test/resource');
    });

    expect($recordedDelaysMs)->toBe([250, 250]);
});

it('ignores Retry-After on statuses other than 429 and 503', function (): void {
    Http::fake([
        'https://upstream.test/*' => Http::sequence()
            ->push(['error' => 'server error'], 500, ['Retry-After' => '3600'])
            ->push(['ok' => true], 200),
    ]);

    $recordedDelaysMs = recordedRetryDelaysMs(function (): void {
        OutboundHttpRetry::make(retryTimes: 2, retryDelayMs: 250, retryAfterMaxMs: 60000)
            ->apply(Http::acceptJson())
            ->get('https://upstream.test/resource');
    });

    expect($recordedDelaysMs)->toBe([250]);
});

it('does not retry permanent client failures', function (): void {
    Http::fake([
        'https://upstream.test/*' => Http::sequence()
            ->push(['error' => 'not found'], 404)
            ->push(['ok' => true], 200),
    ]);

    $response = OutboundHttpRetry::make(retryTimes: 3, retryDelayMs: 250)
        ->apply(Http::acceptJson())
        ->get('https://upstream.test/resource');

    expect($response->status())->toBe(404);

    Http::assertSentCount(1);
});

it('does not retry redirect responses when Laravel provides no response exception', function (): void {
    Http::fake([
        'https://upstream.test/*' => Http::sequence()
            ->push('', 302, ['Location' => 'https://upstream.test/redirected'])
            ->push(['ok' => true], 200),
    ]);

    $response = OutboundHttpRetry::make(retryTimes: 3, retryDelayMs: 250)
        ->apply(Http::withOptions(['allow_redirects' => false]))
        ->get('https://upstream.test/resource');

    expect($response->status())->toBe(302);

    Http::assertSentCount(1);
});

it('does not retry status codes outside the HTTP server error range', function (): void {
    $response = Mockery::mock(Response::class);
    $response->shouldReceive('status')->andReturn(600);
    $response->expects('toPsrResponse')->andReturn(new GuzzleHttp\Psr7\Response(599));
    $exception = new RequestException($response);
    $method = new ReflectionMethod(OutboundHttpRetry::class, 'shouldRetry');

    expect($method->invoke(OutboundHttpRetry::make(), $exception))->toBeFalse();
});

it('retries connection failures', function (): void {
    Http::fake([
        'https://upstream.test/*' => Http::sequence()
            ->pushFailedConnection('Connection reset')
            ->push(['ok' => true], 200),
    ]);

    $response = null;

    $recordedDelaysMs = recordedRetryDelaysMs(function () use (&$response): void {
        $response = OutboundHttpRetry::make(retryTimes: 2, retryDelayMs: 250)
            ->apply(Http::acceptJson())
            ->get('https://upstream.test/resource');
    });

    expect($response?->status())->toBe(200)
        ->and($recordedDelaysMs)->toBe([250]);

    Http::assertSentCount(2);
});

it('caps an excessively large numeric Retry-After value without overflowing', function (): void {
    Http::fake([
        'https://upstream.test/*' => Http::sequence()
            ->push(['error' => 'rate limited'], 429, ['Retry-After' => '999999999999999999999999999999'])
            ->push(['ok' => true], 200),
    ]);

    $recordedDelaysMs = recordedRetryDelaysMs(function (): void {
        OutboundHttpRetry::make(retryTimes: 2, retryDelayMs: 250, retryAfterMaxMs: 1500)
            ->apply(Http::acceptJson())
            ->get('https://upstream.test/resource');
    });

    expect($recordedDelaysMs)->toBe([1500]);
});

it('reads the attempt count, fallback delay, and cap from a package config prefix', function (): void {
    config()->set('capell-example.http.retry_times', 2);
    config()->set('capell-example.http.retry_delay_ms', 125);
    config()->set('capell-example.http.retry_after_max_ms', 400);

    $policy = OutboundHttpRetry::fromConfig('capell-example.http');

    expect($policy->retryTimes())->toBe(2);

    Http::fake([
        'https://upstream.test/*' => Http::sequence()
            ->push(['error' => 'rate limited'], 429, ['Retry-After' => '3600'])
            ->push(['ok' => true], 200),
    ]);

    $recordedDelaysMs = recordedRetryDelaysMs(function () use ($policy): void {
        $policy->apply(Http::acceptJson())->get('https://upstream.test/resource');
    });

    expect($recordedDelaysMs)->toBe([400]);
});

it('accepts a config prefix with a trailing separator', function (): void {
    config()->set('capell-example.http.retry_times', 4);

    expect(OutboundHttpRetry::fromConfig('capell-example.http.')->retryTimes())->toBe(4);
});

it('uses the caller supplied defaults when config keys are missing or non numeric', function (): void {
    config()->set('capell-example.http.retry_times', 'not-a-number');

    $policy = OutboundHttpRetry::fromConfig(
        'capell-example.http',
        retryTimes: 5,
        retryDelayMs: 75,
        retryAfterMaxMs: 900,
    );

    expect($policy->retryTimes())->toBe(5);

    Http::fake([
        'https://upstream.test/*' => Http::sequence()
            ->push(['error' => 'rate limited'], 429, ['Retry-After' => '3600'])
            ->push(['ok' => true], 200),
    ]);

    $recordedDelaysMs = recordedRetryDelaysMs(function () use ($policy): void {
        $policy->apply(Http::acceptJson())->get('https://upstream.test/resource');
    });

    expect($recordedDelaysMs)->toBe([900]);
});

it('floors the attempt count at one and the delays at zero', function (): void {
    config()->set('capell-example.http.retry_times', 0);
    config()->set('capell-example.http.retry_delay_ms', -100);
    config()->set('capell-example.http.retry_after_max_ms', -100);

    $policy = OutboundHttpRetry::fromConfig('capell-example.http');

    expect($policy->retryTimes())->toBe(1);

    Http::fake([
        'https://upstream.test/*' => Http::response(['error' => 'rate limited'], 429, ['Retry-After' => '30']),
    ]);

    $response = null;

    $recordedDelaysMs = recordedRetryDelaysMs(function () use ($policy, &$response): void {
        $response = $policy->apply(Http::acceptJson())->get('https://upstream.test/resource');
    });

    expect($response?->status())->toBe(429)
        ->and($recordedDelaysMs)->toBe([]);

    Http::assertSentCount(1);
});

it('exposes the documented default attempt count', function (): void {
    expect(OutboundHttpRetry::DEFAULT_RETRY_TIMES)->toBe(3)
        ->and(OutboundHttpRetry::DEFAULT_RETRY_DELAY_MS)->toBe(500)
        ->and(OutboundHttpRetry::DEFAULT_RETRY_AFTER_MAX_MS)->toBe(60000)
        ->and(OutboundHttpRetry::fromConfig('capell-example-unconfigured.http')->retryTimes())->toBe(3);
});
