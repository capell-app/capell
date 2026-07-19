<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\Tables\PagesTable;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Page;
use Capell\Core\Support\Publishing\PublishSentinel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * @param  array<string, mixed>  $data
 * @return Builder<Page>
 */
function applyPublishStatusFilter(array $data): Builder
{
    $method = new ReflectionMethod(PagesTable::class, 'applyPublishStatusFilterQuery');

    /** @var Builder<Page> $builder */
    $builder = $method->invoke(null, Page::query(), $data);

    return $builder;
}

/** @return array<string, string> */
function publishStatusFilterOptions(): array
{
    $method = new ReflectionMethod(PagesTable::class, 'getPublishStatusFilterOptions');

    /** @var array<string, string> $options */
    $options = $method->invoke(null);

    return $options;
}

it('builds publish status options from the visibility enum except for deleted', function (): void {
    expect(publishStatusFilterOptions())->toBe([
        PublishVisibilityStateEnum::draft->value => PublishVisibilityStateEnum::draft->getLabel(),
        PublishVisibilityStateEnum::scheduled->value => PublishVisibilityStateEnum::scheduled->getLabel(),
        PublishVisibilityStateEnum::published->value => PublishVisibilityStateEnum::published->getLabel(),
        PublishVisibilityStateEnum::expired->value => PublishVisibilityStateEnum::expired->getLabel(),
    ])->not->toHaveKey(PublishVisibilityStateEnum::deleted->value);
});

it('partitions pages across every publish status filter option', function (): void {
    $publishedPage = Page::factory()->createOne([
        'visible_from' => CarbonImmutable::now()->subDay(),
        'visible_until' => null,
    ]);
    $scheduledPage = Page::factory()->createOne([
        'visible_from' => CarbonImmutable::now()->addWeek(),
        'visible_until' => null,
    ]);
    $draftPage = Page::factory()->createOne([
        'visible_from' => PublishSentinel::draftValue(),
        'visible_until' => null,
    ]);
    $expiredPage = Page::factory()->createOne([
        'visible_from' => CarbonImmutable::now()->subMonth(),
        'visible_until' => CarbonImmutable::now()->subDay(),
    ]);

    expect(applyPublishStatusFilter(['value' => 'draft'])->pluck('id')->all())->toBe([$draftPage->id])
        ->and(applyPublishStatusFilter(['value' => 'scheduled'])->pluck('id')->all())->toBe([$scheduledPage->id])
        ->and(applyPublishStatusFilter(['value' => 'expired'])->pluck('id')->all())->toBe([$expiredPage->id])
        ->and(applyPublishStatusFilter(['value' => 'published'])->pluck('id')->all())->toBe([$publishedPage->id]);
});

it('returns the unfiltered query for empty or unknown filter values', function (): void {
    Page::factory()->createOne([
        'visible_from' => CarbonImmutable::now()->subDay(),
        'visible_until' => null,
    ]);
    Page::factory()->createOne([
        'visible_from' => PublishSentinel::draftValue(),
        'visible_until' => null,
    ]);

    expect(applyPublishStatusFilter(['value' => null])->count())->toBe(2)
        ->and(applyPublishStatusFilter(['value' => ''])->count())->toBe(2)
        ->and(applyPublishStatusFilter(['value' => 'nonsense'])->count())->toBe(2);
});
