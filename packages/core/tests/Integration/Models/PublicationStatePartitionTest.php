<?php

declare(strict_types=1);

use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Page;
use Capell\Core\Support\Publishing\PublishSentinel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-07-14 12:00:00');
    CarbonImmutable::setTestNow($this->now);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('partitions every publication date shape into exactly one state scope', function (): void {
    $shapes = [
        'null dates' => [null, null, false],
        'past start' => [$this->now->subDay(), null, false],
        'start now' => [$this->now, null, false],
        'future start' => [$this->now->addDay(), null, false],
        'draft sentinel' => [PublishSentinel::draftValue($this->now), null, false],
        'expiry now beats draft' => [PublishSentinel::draftValue($this->now), $this->now, false],
        'past expiry beats schedule' => [$this->now->addDay(), $this->now->subSecond(), false],
        'future expiry remains published' => [$this->now->subDay(), $this->now->addDay(), false],
        'deleted beats expiry' => [$this->now->subDay(), $this->now->subSecond(), true],
    ];

    foreach ($shapes as $shape) {
        [$from, $until, $deleted] = $shape;
        $page = Page::factory()->createOne([
            'visible_from' => $from,
            'visible_until' => $until,
        ]);

        if ($deleted) {
            $page->delete();
            $page = $page->refresh();
        }

        $matches = collect(PublishVisibilityStateEnum::cases())
            ->filter(fn (PublishVisibilityStateEnum $state): bool => publicationStateQuery($state)
                ->whereKey($page->getKey())
                ->exists());

        expect($matches)->toHaveCount(1)
            ->and($matches->first())->toBe($page->publishVisibilityState($this->now));
    }
});

/** @return Builder<Page> */
function publicationStateQuery(PublishVisibilityStateEnum $state): Builder
{
    $query = Page::query();

    return match ($state) {
        PublishVisibilityStateEnum::draft => $query->draft(),
        PublishVisibilityStateEnum::scheduled => $query->scheduled(),
        PublishVisibilityStateEnum::published => $query->published(),
        PublishVisibilityStateEnum::expired => $query->expired(),
        PublishVisibilityStateEnum::deleted => $query->deleted(),
    };
}
