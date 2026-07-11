<?php

declare(strict_types=1);

use Capell\Admin\Actions\DismissHintAction;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\DB;

it('records a dismissal for the given user and hint key', function (): void {
    $user = User::factory()->createOne();

    DismissHintAction::run($user, 'welcome.tour');

    $raw = DB::table('users')->where('id', $user->getKey())->value('dismissed_hints');
    $dismissed = json_decode((string) $raw, true);

    expect($dismissed)->toContain('welcome.tour');
});

it('is idempotent — dismissing twice records the key once', function (): void {
    $user = User::factory()->createOne();

    DismissHintAction::run($user, 'welcome.tour');
    DismissHintAction::run($user, 'welcome.tour');

    $raw = DB::table('users')->where('id', $user->getKey())->value('dismissed_hints');
    /** @var list<string> $dismissed */
    $dismissed = json_decode((string) $raw, true);

    expect(array_count_values($dismissed)['welcome.tour'])->toBe(1);
});

it('keeps dismissals isolated per user', function (): void {
    $userA = User::factory()->createOne();
    $userB = User::factory()->createOne();

    DismissHintAction::run($userA, 'welcome.tour');

    $rawA = DB::table('users')->where('id', $userA->getKey())->value('dismissed_hints');
    $rawB = DB::table('users')->where('id', $userB->getKey())->value('dismissed_hints');

    $dismissedA = json_decode((string) $rawA, true);
    $dismissedB = is_string($rawB) ? json_decode($rawB, true) : [];

    expect(in_array('welcome.tour', $dismissedA ?? [], true))->toBeTrue()
        ->and(in_array('welcome.tour', $dismissedB ?? [], true))->toBeFalse();
});
