<?php

declare(strict_types=1);

use Capell\Core\Actions\ContentLocks\AcquireContentLockAction;
use Capell\Core\Actions\ContentLocks\FindConflictingContentLockAction;
use Capell\Core\Actions\ContentLocks\ForceContentLockAction;
use Capell\Core\Models\ContentLock;
use Capell\Core\Models\Page;
use Capell\Tests\Fixtures\Models\User as FixtureUser;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

it('acquires and renews a content lock for the same editor', function (): void {
    $userModel = config('auth.providers.users.model');
    assert(is_string($userModel) && is_subclass_of($userModel, User::class));
    assert(is_a($userModel, FixtureUser::class, true));

    $editor = $userModel::factory()->createOne();
    $page = Page::factory()->createOne();

    Date::setTestNow('2026-05-31 10:00:00');

    $lock = AcquireContentLockAction::run($page, $editor, ttlMinutes: 15);

    expect($lock->user_id)->toBe($editor->getKey())
        ->and($lock->model_type)->toBe($page->getMorphClass())
        ->and($lock->model_id)->toBe($page->getKey())
        ->and($lock->expires_at->toDateTimeString())->toBe('2026-05-31 10:15:00');

    Date::setTestNow('2026-05-31 10:05:00');

    $renewedLock = AcquireContentLockAction::run($page, $editor, ttlMinutes: 15);

    expect($renewedLock->getKey())->toBe($lock->getKey())
        ->and($renewedLock->expires_at->toDateTimeString())->toBe('2026-05-31 10:20:00')
        ->and(ContentLock::query()->count())->toBe(1);

    Date::setTestNow();
});

it('returns an active conflicting lock without replacing it', function (): void {
    $userModel = config('auth.providers.users.model');
    assert(is_string($userModel) && is_subclass_of($userModel, User::class));
    assert(is_a($userModel, FixtureUser::class, true));

    $owner = $userModel::factory()->createOne(['name' => 'Ben']);
    $otherEditor = $userModel::factory()->createOne();
    $page = Page::factory()->createOne();

    Date::setTestNow('2026-05-31 10:00:00');

    $ownerLock = AcquireContentLockAction::run($page, $owner, ttlMinutes: 15);
    $returnedLock = AcquireContentLockAction::run($page, $otherEditor, ttlMinutes: 15);
    $conflictingLock = FindConflictingContentLockAction::run($page, $otherEditor);

    expect($returnedLock->getKey())->toBe($ownerLock->getKey())
        ->and($returnedLock->user_id)->toBe($owner->getKey())
        ->and($conflictingLock?->user_id)->toBe($owner->getKey())
        ->and($conflictingLock?->user?->getAttribute('name'))->toBe('Ben')
        ->and(ContentLock::query()->count())->toBe(1);

    Date::setTestNow();
});

it('replaces an expired content lock for the next editor', function (): void {
    $userModel = config('auth.providers.users.model');
    assert(is_string($userModel) && is_subclass_of($userModel, User::class));
    assert(is_a($userModel, FixtureUser::class, true));

    $owner = $userModel::factory()->createOne();
    $nextEditor = $userModel::factory()->createOne();
    $page = Page::factory()->createOne();

    Date::setTestNow('2026-05-31 10:00:00');

    AcquireContentLockAction::run($page, $owner, ttlMinutes: 15);

    Date::setTestNow('2026-05-31 10:16:00');

    $lock = AcquireContentLockAction::run($page, $nextEditor, ttlMinutes: 15);

    expect($lock->user_id)->toBe($nextEditor->getKey())
        ->and($lock->expires_at->toDateTimeString())->toBe('2026-05-31 10:31:00')
        ->and(FindConflictingContentLockAction::run($page, $nextEditor))->toBeNull()
        ->and(ContentLock::query()->count())->toBe(1);

    Date::setTestNow();
});

it('forces an active content lock to the requested editor', function (): void {
    $userModel = config('auth.providers.users.model');
    assert(is_string($userModel) && is_subclass_of($userModel, User::class));
    assert(is_a($userModel, FixtureUser::class, true));

    $owner = $userModel::factory()->createOne();
    $nextEditor = $userModel::factory()->createOne();
    $page = Page::factory()->createOne();

    Date::setTestNow('2026-05-31 10:00:00');

    $ownerLock = AcquireContentLockAction::run($page, $owner, ttlMinutes: 15);

    $lock = ForceContentLockAction::run($page, $nextEditor, ttlMinutes: 10);

    expect($lock->getKey())->toBe($ownerLock->getKey())
        ->and($lock->user_id)->toBe($nextEditor->getKey())
        ->and($lock->expires_at->toDateTimeString())->toBe('2026-05-31 10:10:00')
        ->and(ContentLock::query()->count())->toBe(1)
        ->and(FindConflictingContentLockAction::run($page, $nextEditor))->toBeNull();

    Date::setTestNow();
});

it('preserves the winner when acquisition races after an empty lookup', function (bool $sameEditor): void {
    $userModel = config('auth.providers.users.model');
    assert(is_string($userModel) && is_subclass_of($userModel, FixtureUser::class));
    $editor = $userModel::factory()->createOne();
    $winner = $sameEditor ? $editor : $userModel::factory()->createOne();
    $page = Page::factory()->createOne();
    Date::setTestNow('2026-05-31 10:00:00');
    $armed = true;

    DB::listen(function (QueryExecuted $query) use (&$armed, $page, $winner): void {
        if (! $armed || ! str_starts_with(strtolower($query->sql), 'select') || ! str_contains($query->sql, 'content_locks')) {
            return;
        }

        $armed = false;
        ContentLock::query()->create([
            'model_type' => $page->getMorphClass(),
            'model_id' => $page->getKey(),
            'user_id' => $winner->getKey(),
            'expires_at' => Date::now()->addMinutes(5),
        ]);
    });

    try {
        $lock = AcquireContentLockAction::run($page, $editor, ttlMinutes: 15);
        expect($armed)->toBeFalse()
            ->and(ContentLock::query()->count())->toBe(1)
            ->and($lock->user_id)->toBe($winner->getKey())
            ->and($lock->expires_at->toDateTimeString())->toBe($sameEditor ? '2026-05-31 10:15:00' : '2026-05-31 10:05:00')
            ->and($lock->user?->getKey())->toBe($winner->getKey());
    } finally {
        $armed = false;
        Date::setTestNow();
    }
})->with([true, false]);
