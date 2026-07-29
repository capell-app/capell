<?php

declare(strict_types=1);

use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Actions\InvalidateDueScheduledPublicationCachesAction;
use Capell\Frontend\Actions\PersistScheduledPublicationInvalidationCheckpointAction;
use Capell\Frontend\Settings\FrontendSettings;
use Capell\Frontend\Support\Cache\FragmentCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-23 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('invalidates page and fragment caches when scheduled visibility becomes effective', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $blueprint = Blueprint::factory()->page()->create();

    $publishing = Page::factory()
        ->site($site)
        ->type($blueprint)
        ->withTranslations($language, [], slug: 'publishing')
        ->create(['visible_from' => CarbonImmutable::now()->subMinute()]);
    $expiring = Page::factory()
        ->site($site)
        ->type($blueprint)
        ->withTranslations($language, [], slug: 'expiring')
        ->create([
            'visible_from' => CarbonImmutable::now()->subDay(),
            'visible_until' => CarbonImmutable::now()->subSeconds(30),
        ]);
    $future = Page::factory()
        ->site($site)
        ->type($blueprint)
        ->withTranslations($language, [], slug: 'future')
        ->create(['visible_from' => CarbonImmutable::now()->addMinute()]);

    $fragments = resolve(FragmentCache::class);

    foreach ([$publishing, $expiring, $future] as $page) {
        $fragments->remember(
            'scheduled-publication-' . $page->id,
            static fn (): string => 'cached',
            surrogateKeys: ['page-' . $page->id],
        );
    }

    $invalidated = InvalidateDueScheduledPublicationCachesAction::run();

    expect($invalidated)->toBe(2)
        ->and(Cache::has('fragment:scheduled-publication-' . $publishing->id))->toBeFalse()
        ->and(Cache::has('fragment:scheduled-publication-' . $expiring->id))->toBeFalse()
        ->and(Cache::has('fragment:scheduled-publication-' . $future->id))->toBeTrue()
        ->and(resolve(FrontendSettings::class)->refresh()->scheduled_publication_invalidation_checkpoint)
        ->toBe(CarbonImmutable::now()->toIso8601String());
});

it('resumes from its checkpoint without repeatedly invalidating older transitions', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $blueprint = Blueprint::factory()->page()->create();
    $page = Page::factory()
        ->site($site)
        ->type($blueprint)
        ->withTranslations($language, [], slug: 'scheduled')
        ->create(['visible_from' => CarbonImmutable::now()->subMinute()]);

    expect(InvalidateDueScheduledPublicationCachesAction::run())->toBe(1);

    resolve(FragmentCache::class)->remember(
        'scheduled-publication-' . $page->id,
        static fn (): string => 'cached again',
        surrogateKeys: ['page-' . $page->id],
    );

    CarbonImmutable::setTestNow('2026-07-23 12:05:00');

    expect(InvalidateDueScheduledPublicationCachesAction::run())->toBe(0)
        ->and(Cache::has('fragment:scheduled-publication-' . $page->id))->toBeTrue();
});

it('keeps its durable checkpoint across a cache flush', function (): void {
    expect(InvalidateDueScheduledPublicationCachesAction::run())->toBe(0);

    Cache::flush();
    app()->forgetInstance(FrontendSettings::class);
    CarbonImmutable::setTestNow('2026-07-23 12:05:00');

    $page = makeScheduledPublicationPage(CarbonImmutable::now()->subMinutes(3));
    cacheScheduledPublicationFragment($page);

    expect(InvalidateDueScheduledPublicationCachesAction::run())->toBe(1)
        ->and(Cache::has('fragment:scheduled-publication-' . $page->id))->toBeFalse();
});

it('scans from a valid checkpoint after long scheduler downtime', function (): void {
    setScheduledPublicationCheckpoint(CarbonImmutable::now()->subHours(4)->toIso8601String());

    $page = makeScheduledPublicationPage(CarbonImmutable::now()->subHours(3));
    cacheScheduledPublicationFragment($page);

    expect(InvalidateDueScheduledPublicationCachesAction::run())->toBe(1)
        ->and(Cache::has('fragment:scheduled-publication-' . $page->id))->toBeFalse();
});

it('overlaps valid checkpoint scans by five seconds', function (): void {
    $checkpoint = CarbonImmutable::now()->subMinute();
    setScheduledPublicationCheckpoint($checkpoint->toIso8601String());

    $insideOverlap = makeScheduledPublicationPage($checkpoint->subSeconds(5));
    $outsideOverlap = makeScheduledPublicationPage($checkpoint->subSeconds(6));
    cacheScheduledPublicationFragment($insideOverlap);
    cacheScheduledPublicationFragment($outsideOverlap);

    expect(InvalidateDueScheduledPublicationCachesAction::run())->toBe(1)
        ->and(Cache::has('fragment:scheduled-publication-' . $insideOverlap->id))->toBeFalse()
        ->and(Cache::has('fragment:scheduled-publication-' . $outsideOverlap->id))->toBeTrue();
});

it('normalizes a cross-offset checkpoint before scanning scheduled transitions', function (): void {
    $checkpoint = '2026-10-25T01:00:00+01:00';
    $until = CarbonImmutable::parse('2026-10-25T00:05:00+00:00');
    setScheduledPublicationCheckpoint($checkpoint);

    $page = makeScheduledPublicationPage(
        CarbonImmutable::parse('2026-10-25T00:01:00+00:00'),
    );
    cacheScheduledPublicationFragment($page);

    Event::listen(
        FrontendSurrogateKeysInvalidated::class,
        static function () use ($checkpoint): void {
            expect(scheduledPublicationCheckpointPayload())->toBe($checkpoint);
        },
    );

    expect(InvalidateDueScheduledPublicationCachesAction::run($until))->toBe(1)
        ->and(Cache::has('fragment:scheduled-publication-' . $page->id))->toBeFalse()
        ->and(scheduledPublicationCheckpointPayload())->toBe($until->toIso8601String());
});

it('falls back to a two-minute scan for unusable checkpoints', function (?string $checkpoint): void {
    setScheduledPublicationCheckpoint($checkpoint);

    $insideFallback = makeScheduledPublicationPage(CarbonImmutable::now()->subSeconds(120));
    $outsideFallback = makeScheduledPublicationPage(CarbonImmutable::now()->subSeconds(121));
    cacheScheduledPublicationFragment($insideFallback);
    cacheScheduledPublicationFragment($outsideFallback);

    expect(InvalidateDueScheduledPublicationCachesAction::run())->toBe(1)
        ->and(Cache::has('fragment:scheduled-publication-' . $insideFallback->id))->toBeFalse()
        ->and(Cache::has('fragment:scheduled-publication-' . $outsideFallback->id))->toBeTrue();
})->with([
    'missing checkpoint' => null,
    'malformed checkpoint' => 'not-a-checkpoint',
    'future checkpoint' => fn (): string => CarbonImmutable::now()->addMinute()->toIso8601String(),
]);

it('does not advance its checkpoint when invalidation fails', function (): void {
    $checkpoint = CarbonImmutable::now()->subMinutes(5)->toIso8601String();
    setScheduledPublicationCheckpoint($checkpoint);

    makeScheduledPublicationPage(CarbonImmutable::now()->subMinute());

    Event::listen(
        FrontendSurrogateKeysInvalidated::class,
        static function (): never {
            throw new RuntimeException('Scheduled publication invalidation failed.');
        },
    );

    expect(fn (): int => InvalidateDueScheduledPublicationCachesAction::run())
        ->toThrow(RuntimeException::class, 'Scheduled publication invalidation failed.')
        ->and(scheduledPublicationCheckpointPayload())->toBe($checkpoint);
});

it('does not advance its durable checkpoint when the settings write fails', function (): void {
    $checkpoint = CarbonImmutable::now()->subMinutes(5)->toIso8601String();
    setScheduledPublicationCheckpoint($checkpoint);

    $page = makeScheduledPublicationPage(CarbonImmutable::now()->subMinute());
    cacheScheduledPublicationFragment($page);

    PersistScheduledPublicationInvalidationCheckpointAction::shouldRun()
        ->once()
        ->andThrow(new RuntimeException('Scheduled publication checkpoint write failed.'));

    expect(fn (): int => InvalidateDueScheduledPublicationCachesAction::run())
        ->toThrow(RuntimeException::class, 'Scheduled publication checkpoint write failed.')
        ->and(Cache::has('fragment:scheduled-publication-' . $page->id))->toBeFalse()
        ->and(scheduledPublicationCheckpointPayload())->toBe($checkpoint);
});

it('does not overwrite a concurrent frontend settings update when persisting its checkpoint', function (): void {
    setScheduledPublicationCheckpoint(CarbonImmutable::now()->subMinutes(5)->toIso8601String());

    makeScheduledPublicationPage(CarbonImmutable::now()->subMinute());

    Event::listen(
        FrontendSurrogateKeysInvalidated::class,
        static function (): void {
            $settings = new FrontendSettings;
            $settings->cache_ttl = 7200;
            $settings->save();
        },
    );

    expect(InvalidateDueScheduledPublicationCachesAction::run())->toBe(1)
        ->and(resolve(FrontendSettings::class)->cache_ttl)->toBe(7200)
        ->and(scheduledPublicationCheckpointPayload())->toBe(CarbonImmutable::now()->toIso8601String());
});

it('replaces a primed settings cache after persisting its checkpoint', function (): void {
    $oldCheckpoint = CarbonImmutable::now()->subMinutes(5)->toIso8601String();
    $newCheckpoint = CarbonImmutable::now()->toIso8601String();
    setScheduledPublicationCheckpoint($oldCheckpoint);

    expect(resolve(FrontendSettings::class)->scheduled_publication_invalidation_checkpoint)
        ->toBe($oldCheckpoint);

    PersistScheduledPublicationInvalidationCheckpointAction::run($newCheckpoint);

    expect(resolve(FrontendSettings::class)->scheduled_publication_invalidation_checkpoint)
        ->toBe($newCheckpoint);
});

function makeScheduledPublicationPage(CarbonImmutable $visibleFrom): Page
{
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $blueprint = Blueprint::factory()->page()->create();

    return Page::factory()
        ->site($site)
        ->type($blueprint)
        ->withTranslations($language, [], slug: 'scheduled-' . $visibleFrom->getTimestamp())
        ->create(['visible_from' => $visibleFrom]);
}

function cacheScheduledPublicationFragment(Page $page): void
{
    resolve(FragmentCache::class)->remember(
        'scheduled-publication-' . $page->id,
        static fn (): string => 'cached',
        surrogateKeys: ['page-' . $page->id],
    );
}

function setScheduledPublicationCheckpoint(?string $checkpoint): void
{
    $settings = resolve(FrontendSettings::class);
    $settings->scheduled_publication_invalidation_checkpoint = $checkpoint;
    $settings->save();
}

function scheduledPublicationCheckpointPayload(): mixed
{
    return resolve(FrontendSettings::class)
        ->getRepository()
        ->getPropertiesInGroup(FrontendSettings::group())['scheduled_publication_invalidation_checkpoint'];
}
