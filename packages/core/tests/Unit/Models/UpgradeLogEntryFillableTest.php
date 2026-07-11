<?php

declare(strict_types=1);

use Capell\Core\Models\UpgradeLogEntry;
use Illuminate\Database\Eloquent\MassAssignmentException;

it('allows mass-assignment of expected columns', function (): void {
    $fillableData = [
        'type' => 'step',
        'key' => 'migration_001',
        'package' => 'capell-core',
        'status' => 'success',
        'ran_at' => now(),
        'meta' => ['message' => 'test data'],
    ];

    $entry = UpgradeLogEntry::query()->create($fillableData);

    expect($entry->type)->toBe('step')
        ->and($entry->key)->toBe('migration_001')
        ->and($entry->package)->toBe('capell-core')
        ->and($entry->status)->toBe('success')
        ->and($entry->meta)->toBe(['message' => 'test data']);

    expect(
        UpgradeLogEntry::query()
            ->where('type', 'step')
            ->where('key', 'migration_001')
            ->where('package', 'capell-core')
            ->where('status', 'success')
            ->exists(),
    )->toBeTrue();
});

it('does not allow mass-assignment of arbitrary attributes', function (): void {
    $entry = new UpgradeLogEntry;

    // Attempting to fill arbitrary attributes should throw MassAssignmentException
    expect(fn () => $entry->fill([
        'type' => 'step',
        'key' => 'test_key',
        'status' => 'pending',
        'is_admin' => true, // Arbitrary attribute outside fillable list
    ]))->toThrow(MassAssignmentException::class);

    $entryWithId = new UpgradeLogEntry;

    // id should not be in fillable list
    expect(fn () => $entryWithId->fill([
        'type' => 'version_snapshot',
        'id' => 99999, // id should not be fillable
    ]))->toThrow(MassAssignmentException::class);
});
