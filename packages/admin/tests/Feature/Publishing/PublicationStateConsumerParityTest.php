<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\ResolvePagePublishStateAction;
use Capell\Admin\Actions\Pages\ResolvePublishPanelViewAction;
use Capell\Admin\Enums\PublishPanelStatusEnum;
use Capell\Admin\Support\Pages\DefaultPageTableStatusResolver;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Page;
use Capell\Core\Support\Publishing\PublishSentinel;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-07-14 12:00:00');
    CarbonImmutable::setTestNow($this->now);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('keeps core scopes table labels panel and workflow data on the same state', function (
    ?CarbonImmutable $from,
    ?CarbonImmutable $until,
    PublishVisibilityStateEnum $state,
    PublishPanelStatusEnum $panelStatus,
): void {
    $page = Page::factory()->createOne([
        'visible_from' => $from,
        'visible_until' => $until,
    ]);
    $panel = ResolvePublishPanelViewAction::run($page);
    $workflow = ResolvePagePublishStateAction::run($page);
    $table = resolve(DefaultPageTableStatusResolver::class)->resolve($page);
    $query = Page::query();
    $tableLabel = match ($state) {
        PublishVisibilityStateEnum::draft => __('capell-admin::table.page_status_draft'),
        PublishVisibilityStateEnum::scheduled => __('capell-admin::table.page_status_scheduled'),
        PublishVisibilityStateEnum::published => __('capell-admin::table.page_status_published'),
        PublishVisibilityStateEnum::expired => __('capell-admin::table.page_status_expired'),
        PublishVisibilityStateEnum::deleted => __('capell-admin::table.page_status_deleted'),
    };
    $query = match ($state) {
        PublishVisibilityStateEnum::draft => $query->draft(),
        PublishVisibilityStateEnum::scheduled => $query->scheduled(),
        PublishVisibilityStateEnum::published => $query->published(),
        PublishVisibilityStateEnum::expired => $query->expired(),
        PublishVisibilityStateEnum::deleted => $query->deleted(),
    };

    expect($page->publishVisibilityState($this->now))->toBe($state)
        ->and($query->whereKey($page->getKey())->exists())->toBeTrue()
        ->and($panel->status)->toBe($panelStatus)
        ->and($table->label)->toBe($tableLabel)
        ->and($workflow->isDraft)->toBe($state === PublishVisibilityStateEnum::draft)
        ->and($workflow->hasScheduledPublish())->toBe($state === PublishVisibilityStateEnum::scheduled)
        ->and($workflow->isExpired())->toBe($state === PublishVisibilityStateEnum::expired);
})->with(function (): array {
    $now = CarbonImmutable::parse('2026-07-14 12:00:00');

    return [
        'published' => [null, null, PublishVisibilityStateEnum::published, PublishPanelStatusEnum::published],
        'scheduled' => [$now->addDay(), null, PublishVisibilityStateEnum::scheduled, PublishPanelStatusEnum::scheduled],
        'draft' => [PublishSentinel::draftValue($now), null, PublishVisibilityStateEnum::draft, PublishPanelStatusEnum::draft],
        'expired at boundary' => [$now->subDay(), $now, PublishVisibilityStateEnum::expired, PublishPanelStatusEnum::expired],
        'expiry beats draft' => [PublishSentinel::draftValue($now), $now->subSecond(), PublishVisibilityStateEnum::expired, PublishPanelStatusEnum::expired],
    ];
});
