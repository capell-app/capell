<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\CheckForUpdatesAction;
use Capell\Marketplace\Actions\DismissUpdateNoticeAction;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

uses(CreatesAdminUser::class);

it('reports heartbeat failures when checking for marketplace updates', function (): void {
    Log::spy();

    config(['capell-marketplace.marketplace.base_url' => null]);

    $action = resolve(CheckForUpdatesAction::class);

    expect($action->handle())->toBeFalse()
        ->and($action->failureMessage())->toBe('The marketplace URL is not configured. Set CAPELL_MARKETPLACE_URL to the Capell marketplace API URL.');
});

it('dismisses update notices by notice id for the requested user', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-07 10:00:00'));
    $user = test()->createUser();

    $dismissal = DismissUpdateNoticeAction::run($user->id, [
        'notice_id' => 'notice-123',
        'type' => 'update',
        'severity' => 'medium',
    ]);

    expect($dismissal->user_id)->toBe($user->id)
        ->and($dismissal->notice_id)->toBe('notice-123')
        ->and($dismissal->dismissed_until?->toDateTimeString())->toBe('2026-05-14 10:00:00');

    $updatedDismissal = DismissUpdateNoticeAction::run(
        userId: $user->id,
        notice: ['id' => 'notice-123', 'type' => 'update'],
        dismissedUntil: CarbonImmutable::parse('2026-06-01 09:00:00'),
    );

    expect($updatedDismissal->getKey())->toBe($dismissal->getKey())
        ->and($updatedDismissal->dismissed_until?->toDateTimeString())->toBe('2026-06-01 09:00:00')
        ->and($updatedDismissal::query()->count())->toBe(1);
});

it('rejects persistent security notices and notices without identifiers', function (): void {
    expect(fn (): mixed => DismissUpdateNoticeAction::run(42, [
        'notice_id' => 'critical-security',
        'type' => 'security',
        'severity' => 'critical',
    ]))->toThrow(RuntimeException::class, 'High and critical security notices cannot be dismissed');

    expect(fn (): mixed => DismissUpdateNoticeAction::run(42, [
        'type' => 'update',
    ]))->toThrow(RuntimeException::class, 'does not have a notice ID');
});
