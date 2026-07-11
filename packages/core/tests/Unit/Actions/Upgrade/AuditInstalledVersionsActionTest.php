<?php

declare(strict_types=1);

use Capell\Core\Actions\Upgrade\AuditInstalledVersionsAction;
use Capell\Core\Data\VersionAudit;
use Capell\Core\Models\UpgradeLogEntry;

it('flags composer-only, ledger-only, and downgrade cases', function (): void {
    UpgradeLogEntry::query()->create([
        'type' => 'version_snapshot', 'key' => 'capell-app/ledger-only', 'package' => 'capell-app/ledger-only',
        'status' => 'recorded', 'ran_at' => now()->subDay(),
        'meta' => ['to_version' => '1.0.0'],
    ]);
    UpgradeLogEntry::query()->create([
        'type' => 'version_snapshot', 'key' => 'capell-app/capell', 'package' => 'capell-app/capell',
        'status' => 'recorded', 'ran_at' => now()->subDay(),
        'meta' => ['to_version' => '5.0.0'],
    ]);

    $audit = AuditInstalledVersionsAction::run([
        'capell-app/capell' => '4.5.0',
        'capell-app/new-pkg' => '0.1.0',
    ]);

    expect($audit)->toBeInstanceOf(VersionAudit::class)
        ->and($audit->composerOnly)->toBe(['capell-app/new-pkg'])
        ->and($audit->ledgerOnly)->toBe(['capell-app/ledger-only'])
        ->and($audit->downgrades)->toBe(['capell-app/capell' => ['from' => '5.0.0', 'to' => '4.5.0']])
        ->and($audit->hasIssues())->toBeTrue();
});

it('detects downgrades from v-prefixed release tags', function (): void {
    UpgradeLogEntry::query()->create([
        'type' => 'version_snapshot', 'key' => 'capell-app/capell', 'package' => 'capell-app/capell',
        'status' => 'recorded', 'ran_at' => now()->subDay(),
        'meta' => ['to_version' => '99.0.0'],
    ]);

    $audit = AuditInstalledVersionsAction::run([
        'capell-app/capell' => 'v2.0.18',
    ]);

    expect($audit->downgrades)->toBe(['capell-app/capell' => ['from' => '99.0.0', 'to' => 'v2.0.18']]);
});

it('detects downgrades from dev branches against release ledger versions', function (): void {
    UpgradeLogEntry::query()->create([
        'type' => 'version_snapshot', 'key' => 'capell-app/capell', 'package' => 'capell-app/capell',
        'status' => 'recorded', 'ran_at' => now()->subDay(),
        'meta' => ['to_version' => '99.0.0'],
    ]);

    $audit = AuditInstalledVersionsAction::run([
        'capell-app/capell' => 'dev-main',
    ]);

    expect($audit->downgrades)->toBe(['capell-app/capell' => ['from' => '99.0.0', 'to' => 'dev-main']]);
});

it('ignores retired packages that remain only in the upgrade ledger', function (): void {
    foreach (['capell-app/installer', 'capell-app/url-manager', 'capell-app/ordinary-retired-drift'] as $package) {
        UpgradeLogEntry::query()->create([
            'type' => 'version_snapshot', 'key' => $package, 'package' => $package,
            'status' => 'recorded', 'ran_at' => now()->subDay(),
            'meta' => ['to_version' => '1.0.0'],
        ]);
    }

    $audit = AuditInstalledVersionsAction::run([]);

    expect($audit->ledgerOnly)->toBe(['capell-app/ordinary-retired-drift']);
});
