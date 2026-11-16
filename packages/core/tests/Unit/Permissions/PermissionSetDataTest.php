<?php

declare(strict_types=1);

use Capell\Core\Data\Permissions\PermissionData;
use Capell\Core\Data\Permissions\PermissionSetData;

it('lists and finds declared permission keys', function (): void {
    $set = PermissionSetData::of(
        new PermissionData('reports.view', 'View reports'),
        new PermissionData('reports.publish', 'Publish reports', 'Reports', ['admin']),
    );

    expect($set->keys())->toBe(['reports.view', 'reports.publish'])
        ->and($set->has('reports.publish'))->toBeTrue()
        ->and($set->has('reports.delete'))->toBeFalse();
});

it('rejects a key declared twice, including across merged sets', function (): void {
    $first = PermissionSetData::of(new PermissionData('reports.view', 'View reports'));

    expect(fn (): PermissionSetData => PermissionSetData::of(
        new PermissionData('reports.view', 'View reports'),
        new PermissionData('reports.view', 'View reports again'),
    ))->toThrow(InvalidArgumentException::class, 'declared twice')
        ->and(fn (): PermissionSetData => $first->merge($first))->toThrow(InvalidArgumentException::class, 'declared twice');
});

it('rejects permissions without a usable key, label or role', function (string $key, string $label, array $roles, string $message): void {
    expect(fn (): PermissionData => new PermissionData($key, $label, null, $roles))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'empty key' => ['', 'Label', [], 'non-empty string without whitespace'],
    'key with a space' => ['reports view', 'Label', [], 'non-empty string without whitespace'],
    'empty label' => ['reports.view', ' ', [], 'needs a label'],
    'empty role' => ['reports.view', 'Label', [''], 'empty default role'],
]);
