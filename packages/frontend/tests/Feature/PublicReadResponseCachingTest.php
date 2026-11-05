<?php

declare(strict_types=1);

use Capell\Core\Actions\PublicRead\RecordPublicReadEligibilityChangeAction;
use Capell\Core\Contracts\PublicRead\EvaluatesPublicPageReadCandidatesIndividually;
use Capell\Core\Contracts\PublicRead\PublicPageReadEligibility;
use Capell\Core\Contracts\PublicRead\SchedulesPublicReadTransitions;
use Capell\Core\Data\PublicRead\PublicPageReadCandidateData;
use Capell\Core\Support\PublicRead\PublicPageReadEligibilityRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

/**
 * Rules with one scheduled transition: $schedule->at is the moment, null for
 * none, or false when the schedule cannot be read.
 */
function responseCachingRules(): stdClass
{
    $schedule = new stdClass;
    $schedule->at = null;

    resolve(PublicPageReadEligibilityRegistry::class)->register(new readonly class($schedule) implements PublicPageReadEligibility, SchedulesPublicReadTransitions
    {
        use EvaluatesPublicPageReadCandidatesIndividually;

        public function __construct(private stdClass $schedule) {}

        public function key(): string
        {
            return 'response-caching-test';
        }

        public function isPubliclyReadable(PublicPageReadCandidateData $candidate): bool
        {
            return true;
        }

        public function nextPublicReadTransitionAfter(CarbonImmutable $now, ?int $siteId = null): ?CarbonImmutable
        {
            throw_if($this->schedule->at === false, RuntimeException::class, 'The schedule cannot be read.');

            return $this->schedule->at;
        }
    });

    return $schedule;
}

/** A public feed that filters its entries through public-read eligibility, as every feed does. */
function responseCachingFeed(string $cacheControl = 'public, max-age=3600, s-maxage=3600, stale-while-revalidate=3600'): void
{
    Route::get('/response-caching-feed', static function () use ($cacheControl): ResponseFactory|Response {
        $readable = resolve(PublicPageReadEligibilityRegistry::class)->allowsMany([
            new PublicPageReadCandidateData(url: 'https://example.test/article', siteId: 7),
        ]);

        return response(json_encode($readable, JSON_THROW_ON_ERROR), 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => $cacheControl,
        ]);
    });
}

/** @return array<string, int|true> */
function responseCachingDirectives(string $header): array
{
    $directives = [];

    foreach (array_filter(array_map(trim(...), explode(',', $header))) as $directive) {
        [$name, $value] = array_pad(explode('=', $directive, 2), 2, null);
        $directives[mb_strtolower((string) $name)] = $value === null ? true : (int) $value;
    }

    return $directives;
}

it('caps every shared and browser cache lifetime at the time left until the next transition', function (): void {
    $schedule = responseCachingRules();
    $schedule->at = CarbonImmutable::now()->addSeconds(120);
    responseCachingFeed();

    $response = $this->get('/response-caching-feed')->assertOk();
    $directives = responseCachingDirectives((string) $response->headers->get('Cache-Control'));

    expect($directives['public'] ?? null)->toBeTrue()
        ->and($directives['max-age'])->toBeLessThanOrEqual(120)->toBeGreaterThan(0)
        ->and($directives['s-maxage'])->toBeLessThanOrEqual(120)->toBeGreaterThan(0)
        // Freshness and the stale window together never outlast the rules.
        ->and($directives['s-maxage'] + ($directives['stale-while-revalidate'] ?? 0))->toBeLessThanOrEqual(120)
        ->and($response->headers->get('ETag'))->not->toBeNull();
});

it('keeps a lifetime shorter than the time to the next transition', function (): void {
    $schedule = responseCachingRules();
    $schedule->at = CarbonImmutable::now()->addHour();
    responseCachingFeed('public, max-age=300');

    $directives = responseCachingDirectives((string) $this->get('/response-caching-feed')->headers->get('Cache-Control'));

    expect($directives['max-age'])->toBe(300)
        ->and($directives)->not->toHaveKey('s-maxage');
});

it('lets no cache reuse a response without revalidating while the schedule cannot be read', function (): void {
    $schedule = responseCachingRules();
    $schedule->at = false;
    responseCachingFeed();

    $directives = responseCachingDirectives((string) $this->get('/response-caching-feed')->headers->get('Cache-Control'));

    expect($directives['no-cache'] ?? null)->toBeTrue()
        ->and($directives['must-revalidate'] ?? null)->toBeTrue()
        ->and($directives['max-age'] ?? 0)->toBe(0)
        ->and($directives)->not->toHaveKey('s-maxage')
        ->and($directives)->not->toHaveKey('stale-while-revalidate');
});

it('changes the validator when the eligibility revision moves, even when the body does not', function (): void {
    responseCachingRules();
    responseCachingFeed();

    $first = $this->get('/response-caching-feed');
    $etag = (string) $first->headers->get('ETag');

    // The same copy revalidates while the rules stand.
    $this->get('/response-caching-feed', ['If-None-Match' => $etag])->assertStatus(304);

    RecordPublicReadEligibilityChangeAction::run('test');
    app()->forgetScopedInstances();

    $second = $this->get('/response-caching-feed', ['If-None-Match' => $etag])->assertOk();

    expect($second->getContent())->toBe($first->getContent())
        ->and($second->headers->get('ETag'))->not->toBe($etag);
});

it('leaves responses alone when nothing restricts public reads', function (): void {
    responseCachingFeed('public, max-age=300');

    $response = $this->get('/response-caching-feed');

    expect(responseCachingDirectives((string) $response->headers->get('Cache-Control')))->toBe(['max-age' => 300, 'public' => true])
        ->and($response->headers->get('ETag'))->toBeNull();
});

it('gives browsers an explicit lifetime when only shared caches were given one', function (): void {
    $schedule = responseCachingRules();
    $schedule->at = CarbonImmutable::now()->addSeconds(120);
    Route::get('/response-caching-modified', static function (): ResponseFactory|Response {
        resolve(PublicPageReadEligibilityRegistry::class)->allowsMany([new PublicPageReadCandidateData(url: 'https://example.test/article', siteId: 7)]);

        return response('[]', 200, [
            'Cache-Control' => 's-maxage=3600',
            'Last-Modified' => CarbonImmutable::now()->subYear()->toRfc7231String(),
        ]);
    });

    // Without max-age a browser would fall back to heuristic freshness from Last-Modified, past the transition.
    $directives = responseCachingDirectives((string) $this->get('/response-caching-modified')->headers->get('Cache-Control'));

    expect($directives['s-maxage'])->toBeLessThanOrEqual(120)->toBeGreaterThan(0)
        ->and($directives)->toHaveKey('max-age')
        ->and($directives['max-age'])->toBeLessThanOrEqual(120);
});
