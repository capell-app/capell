<?php

declare(strict_types=1);

use Capell\Core\EventSourcing\Rollback\RollbackChangeType;
use Capell\Core\EventSourcing\Rollback\Support\StateDiffer;

it('classifies JSON-safe rollback state by value', function (): void {
    $changes = (new StateDiffer)->diff(
        ['title' => 'Before', 'settings' => ['enabled' => false]],
        ['title' => 'After', 'settings' => ['enabled' => false]],
        includeUnchanged: true,
    );

    expect($changes)->toHaveCount(2)
        ->and($changes[0]->changeType)->toBe(RollbackChangeType::Modified)
        ->and($changes[1]->changeType)->toBe(RollbackChangeType::Unchanged);
});

it('rejects state that cannot cross the JSON serialization boundary', function (): void {
    (new StateDiffer)->diff(
        ['payload' => "\xB1\x31"],
        ['payload' => "\xB1\x31"],
        includeUnchanged: true,
    );
})->throws(JsonException::class);
