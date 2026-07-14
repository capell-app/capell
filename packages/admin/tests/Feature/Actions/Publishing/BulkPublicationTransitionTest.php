<?php

declare(strict_types=1);

use Capell\Admin\Actions\Publishing\PreviewBulkPublicationTransitionAction;
use Capell\Admin\Actions\Publishing\RunBulkPublicationTransitionAction;
use Capell\Core\Contracts\Publishing\AuthorizesPublicationTransition;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Enums\Publishing\PublicationTransitionOutcome;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Page;
use Capell\Core\Support\Publishing\PublishSentinel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-07-14 12:00:00');
    CarbonImmutable::setTestNow($this->now);
    app()->instance(AuthorizesPublicationTransition::class, new class implements AuthorizesPublicationTransition
    {
        public function allows(PublicationTransitionRequestData $request): bool
        {
            return $request->record->getAttribute('name') !== 'Blocked page';
        }
    });
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('uses the same typed outcome counts for bulk preview and execution', function (): void {
    $draft = Page::factory()->createOne([
        'name' => 'Draft page',
        'visible_from' => PublishSentinel::draftValue($this->now),
        'visible_until' => null,
    ]);
    $published = Page::factory()->createOne([
        'name' => 'Published page',
        'visible_from' => $this->now->subDay(),
        'visible_until' => null,
    ]);
    $blocked = Page::factory()->createOne([
        'name' => 'Blocked page',
        'visible_from' => PublishSentinel::draftValue($this->now),
        'visible_until' => null,
    ]);
    $records = new Collection([$draft, $published, $blocked]);
    $actor = new User;

    $preview = PreviewBulkPublicationTransitionAction::run(
        $records,
        $actor,
        PublicationTransition::PublishNow,
        $this->now,
    );

    expect($preview->changed())->toBe(1)
        ->and($preview->unchanged())->toBe(1)
        ->and($preview->count(PublicationTransitionOutcome::Unauthorized))->toBe(1)
        ->and($draft->fresh()->publishVisibilityState($this->now))->toBe(PublishVisibilityStateEnum::draft);

    $executed = RunBulkPublicationTransitionAction::run(
        $records,
        $actor,
        PublicationTransition::PublishNow,
        $this->now,
    );

    expect($executed->counts)->toBe($preview->counts)
        ->and($draft->fresh()->publishVisibilityState($this->now))->toBe(PublishVisibilityStateEnum::published)
        ->and($blocked->fresh()->publishVisibilityState($this->now))->toBe(PublishVisibilityStateEnum::draft);
});

it('reports invalid scheduled transitions without writing them', function (): void {
    $page = Page::factory()->createOne([
        'visible_from' => $this->now->subDay(),
        'visible_until' => null,
    ]);

    $result = RunBulkPublicationTransitionAction::run(
        new Collection([$page]),
        new User,
        PublicationTransition::SchedulePublish,
        $this->now,
        $this->now->subSecond(),
    );

    expect($result->count(PublicationTransitionOutcome::InvalidTransition))->toBe(1)
        ->and($page->fresh()->visible_from->equalTo($this->now->subDay()))->toBeTrue();
});
