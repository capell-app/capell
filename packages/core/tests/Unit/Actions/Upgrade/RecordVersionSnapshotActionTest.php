<?php

declare(strict_types=1);

use Capell\Core\Actions\Upgrade\RecordVersionSnapshotAction;
use Capell\Core\Models\UpgradeLogEntry;

it('writes one version_snapshot row per package with from/to versions', function (): void {
    RecordVersionSnapshotAction::run([
        'capell-app/capell' => '4.5.0',
        'capell-app/foundation-theme' => '1.2.0',
    ]);

    $capell = expectPresent(UpgradeLogEntry::query()->versionSnapshots()->where('key', 'capell-app/capell')->first());
    $foundationTheme = expectPresent(UpgradeLogEntry::query()->versionSnapshots()->where('key', 'capell-app/foundation-theme')->first());

    expect($capell->status)->toBe('recorded')
        ->and($capell->metaGet('to_version'))->toBe('4.5.0')
        ->and($capell->metaGet('from_version'))->toBeNull()
        ->and($foundationTheme->metaGet('to_version'))->toBe('1.2.0');
});

it('captures previous version via from_version when updating', function (): void {
    UpgradeLogEntry::query()->create([
        'type' => 'version_snapshot', 'key' => 'capell-app/capell', 'package' => 'capell-app/capell',
        'status' => 'recorded', 'ran_at' => now()->subDay(),
        'meta' => ['to_version' => '4.4.0'],
    ]);

    RecordVersionSnapshotAction::run(['capell-app/capell' => '4.5.0']);

    $row = expectPresent(UpgradeLogEntry::query()->versionSnapshots()->where('key', 'capell-app/capell')->latest('ran_at')->first());
    expect($row->metaGet('from_version'))->toBe('4.4.0')
        ->and($row->metaGet('to_version'))->toBe('4.5.0');
});

it('does not write unchanged package versions', function (): void {
    UpgradeLogEntry::query()->create([
        'type' => 'version_snapshot', 'key' => 'capell-app/capell', 'package' => 'capell-app/capell',
        'status' => 'recorded', 'ran_at' => now()->subDay(),
        'meta' => ['to_version' => '4.5.0'],
    ]);

    $written = RecordVersionSnapshotAction::run(['capell-app/capell' => '4.5.0']);

    expect($written)->toBe([])
        ->and(UpgradeLogEntry::query()->versionSnapshots()->where('key', 'capell-app/capell')->count())->toBe(1);
});

it('uses id as a latest snapshot tiebreaker within the same second', function (): void {
    $ranAt = now()->startOfSecond();

    UpgradeLogEntry::query()->create([
        'type' => 'version_snapshot', 'key' => 'capell-app/capell', 'package' => 'capell-app/capell',
        'status' => 'recorded', 'ran_at' => $ranAt,
        'meta' => ['to_version' => '4.4.0'],
    ]);
    UpgradeLogEntry::query()->create([
        'type' => 'version_snapshot', 'key' => 'capell-app/capell', 'package' => 'capell-app/capell',
        'status' => 'recorded', 'ran_at' => $ranAt,
        'meta' => ['to_version' => '4.5.0'],
    ]);

    RecordVersionSnapshotAction::run(['capell-app/capell' => '4.6.0']);

    $row = expectPresent(UpgradeLogEntry::query()->versionSnapshots()->where('key', 'capell-app/capell')->latest('id')->first());

    expect($row->metaGet('from_version'))->toBe('4.5.0')
        ->and($row->metaGet('to_version'))->toBe('4.6.0');
});

it('does nothing in dry-run mode', function (): void {
    RecordVersionSnapshotAction::run(['capell-app/capell' => '4.5.0'], dryRun: true);

    expect(UpgradeLogEntry::query()->versionSnapshots()->count())->toBe(0);
});
