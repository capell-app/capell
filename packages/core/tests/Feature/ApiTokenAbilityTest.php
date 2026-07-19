<?php

declare(strict_types=1);

use Capell\Admin\Actions\Tokens\IssueApiTokenAction;
use Capell\Core\Enums\ApiTokenAbility;
use Capell\Tests\Fixtures\Models\User;

it('exposes the closed set of supported API token abilities', function (): void {
    expect(array_column(ApiTokenAbility::cases(), 'value'))->toBe([
        'content:draft-write',
        'content:publish',
        'content:read',
    ]);
});

it('issues a token restricted to declared abilities', function (): void {
    $user = User::factory()->createOne();

    $token = IssueApiTokenAction::run($user, 'agent-bridge', [
        ApiTokenAbility::ContentDraftWrite->value,
    ]);

    $accessToken = $user->tokens()->sole();

    expect($token->plainTextToken)->not->toBe('')
        ->and($accessToken->can(ApiTokenAbility::ContentDraftWrite->value))->toBeTrue()
        ->and($accessToken->can(ApiTokenAbility::ContentPublish->value))->toBeFalse();
});

it('rejects unknown abilities', function (): void {
    $user = User::factory()->createOne();

    expect(fn () => IssueApiTokenAction::run($user, 'x', ['content:everything']))
        ->toThrow(InvalidArgumentException::class);
});
