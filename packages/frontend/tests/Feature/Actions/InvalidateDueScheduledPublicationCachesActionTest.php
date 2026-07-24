<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Actions\InvalidateDueScheduledPublicationCachesAction;
use Capell\Frontend\Support\Cache\FragmentCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-23 12:00:00');
    Cache::forget(InvalidateDueScheduledPublicationCachesAction::CHECKPOINT_CACHE_KEY);
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
        ->and(Cache::get(InvalidateDueScheduledPublicationCachesAction::CHECKPOINT_CACHE_KEY))
        ->toBe(CarbonImmutable::now()->getTimestamp());
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
