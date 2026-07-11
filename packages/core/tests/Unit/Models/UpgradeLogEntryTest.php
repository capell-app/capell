<?php

declare(strict_types=1);

use Capell\Core\Models\UpgradeLogEntry;

it('stores a step row with variable data in meta', function (): void {
    $row = UpgradeLogEntry::query()->create([
        'type' => 'step',
        'key' => 'core.backfill-page-slugs',
        'package' => 'capell-app/capell',
        'status' => 'success',
        'ran_at' => now(),
        'meta' => [
            'duration_ms' => 1234,
            'output' => 'Updated 12 rows',
            'depends_on' => ['core.create-slug-column'],
            'triggered_by' => 'upgrade',
            'from_version' => '4.4.0',
            'to_version' => '4.5.0',
        ],
    ]);

    expect(expectPresent($row->fresh())->meta)
        ->toMatchArray([
            'duration_ms' => 1234,
            'to_version' => '4.5.0',
        ]);
});

it('scope steps()/versionSnapshots() filter by type', function (): void {
    UpgradeLogEntry::query()->create([
        'type' => 'step', 'key' => 'a', 'package' => 'capell-app/capell',
        'status' => 'success', 'ran_at' => now(),
    ]);
    UpgradeLogEntry::query()->create([
        'type' => 'version_snapshot', 'key' => 'capell-app/capell', 'package' => 'capell-app/capell',
        'status' => 'recorded', 'ran_at' => now(),
        'meta' => ['to_version' => '4.5.0'],
    ]);

    expect(UpgradeLogEntry::query()->steps()->count())->toBe(1)
        ->and(UpgradeLogEntry::query()->versionSnapshots()->count())->toBe(1);
});

it('allows multiple failed rows for the same step key', function (): void {
    foreach (range(1, 3) as $attempt) {
        UpgradeLogEntry::query()->create([
            'type' => 'step', 'key' => 'core.retry-me', 'package' => 'capell-app/capell',
            'status' => 'failed', 'ran_at' => now(),
        ]);
    }

    expect(UpgradeLogEntry::query()->steps()->where('key', 'core.retry-me')->count())->toBe(3);
});
