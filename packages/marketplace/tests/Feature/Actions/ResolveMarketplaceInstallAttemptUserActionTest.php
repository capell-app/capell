<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\ResolveMarketplaceInstallAttemptUserAction;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Contracts\Auth\Authenticatable;

uses(CreatesAdminUser::class);

it('resolves the requesting user through the configured auth model', function (): void {
    $user = test()->createUser();
    $attempt = new MarketplaceInstallAttempt([
        'user_id' => (string) $user->getKey(),
    ]);

    $resolvedUser = ResolveMarketplaceInstallAttemptUserAction::run($attempt);

    expect($resolvedUser)->toBeInstanceOf(Authenticatable::class);
    assert($resolvedUser instanceof Authenticatable);
    expect($resolvedUser->getAuthIdentifier())->toBe($user->getAuthIdentifier());
});

it('returns null when the attempt has no requesting user', function (int|string|null $userId): void {
    $attempt = new MarketplaceInstallAttempt(['user_id' => $userId]);

    expect(ResolveMarketplaceInstallAttemptUserAction::run($attempt))->toBeNull();
})->with([
    'null identifier' => null,
    'blank identifier' => '',
    'unknown identifier' => PHP_INT_MAX,
]);

it('returns null when the configured auth model is invalid', function (mixed $userModel): void {
    config(['auth.providers.users.model' => $userModel]);

    $attempt = new MarketplaceInstallAttempt(['user_id' => 1]);

    expect(ResolveMarketplaceInstallAttemptUserAction::run($attempt))->toBeNull();
})->with([
    'missing model configuration' => null,
    'unknown model class' => 'App\\Models\\MissingUser',
    'non-Eloquent class' => stdClass::class,
]);
