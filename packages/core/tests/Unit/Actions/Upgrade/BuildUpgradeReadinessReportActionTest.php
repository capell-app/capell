<?php

declare(strict_types=1);

use Capell\Core\Actions\Upgrade\BuildUpgradeReadinessReportAction;
use Capell\Core\Enums\Upgrade\UpgradeReadinessResult;
use Capell\Core\Facades\CapellCore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('blocks queued admin upgrades when the queue driver is sync', function (): void {
    config(['queue.default' => 'sync']);

    $report = BuildUpgradeReadinessReportAction::run();

    expect($report->result)->toBe(UpgradeReadinessResult::ManualRequired)
        ->and($report->errors)->toContain('Queue driver is sync; run the upgrade manually from the server shell.');
});

it('blocks database queue upgrades when the jobs table is missing', function (): void {
    config(['queue.default' => 'database']);
    Schema::dropIfExists('jobs');

    $report = BuildUpgradeReadinessReportAction::run();

    expect($report->result)->toBe(UpgradeReadinessResult::ManualRequired)
        ->and(implode("\n", $report->errors))->toContain('Database queue table "jobs" is missing.');
});

it('passes readiness checks for an asynchronous queue driver', function (): void {
    config(['queue.default' => 'array']);

    $report = BuildUpgradeReadinessReportAction::run();

    expect($report->result)->toBe(UpgradeReadinessResult::Ready)
        ->and($report->errors)->toBe([]);
});

it('blocks queued upgrades when the readiness cache lock is already held', function (): void {
    config(['queue.default' => 'array']);
    $lock = Cache::lock('capell:upgrade', 60);

    expect($lock->get())->toBeTrue();

    $report = BuildUpgradeReadinessReportAction::run();

    $lock->release();

    expect($report->result)->toBe(UpgradeReadinessResult::ManualRequired)
        ->and(implode("\n", $report->errors))->toContain('Upgrade coordination lock is already held');
});

it('passes the database queue table check when jobs table exists', function (): void {
    config(['queue.default' => 'database']);

    Schema::dropIfExists('jobs');
    Schema::create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    $report = BuildUpgradeReadinessReportAction::run();

    expect(implode("\n", $report->errors))->not->toContain('jobs');
});

it('warns when a legacy manifest upgrade command is missing', function (): void {
    config(['queue.default' => 'array']);

    CapellCore::registerPackage(
        name: 'test',
        path: realpath(__DIR__ . '/../../../../../../tests/fixtures/upgrade-package'),
    );

    $report = BuildUpgradeReadinessReportAction::run();

    expect($report->result)->toBe(UpgradeReadinessResult::Ready)
        ->and($report->errors)->toBe([])
        ->and(implode("\n", $report->warnings))->toContain('Legacy upgrade command "test:upgrade"');
});

it('blocks queued upgrades when operation tables are missing', function (): void {
    config(['queue.default' => 'array']);
    Schema::dropIfExists('capell_upgrade_run_events');
    Schema::dropIfExists('capell_upgrade_runs');

    $report = BuildUpgradeReadinessReportAction::run();

    expect($report->result)->toBe(UpgradeReadinessResult::ManualRequired)
        ->and(implode("\n", $report->errors))->toContain('Upgrade operation tables are missing');
});

it('blocks queued upgrades when database connectivity fails', function (): void {
    $originalDefaultConnection = config('database.default');

    config([
        'queue.default' => 'array',
        'database.default' => 'capell_upgrade_broken',
        'database.connections.capell_upgrade_broken' => [
            'driver' => 'sqlite',
            'database' => '/definitely/missing/capell-upgrade.sqlite',
            'prefix' => '',
        ],
    ]);

    DB::purge('capell_upgrade_broken');

    try {
        $report = BuildUpgradeReadinessReportAction::run();
    } finally {
        config(['database.default' => $originalDefaultConnection]);
        DB::purge('capell_upgrade_broken');
    }

    expect($report->result)->toBe(UpgradeReadinessResult::ManualRequired)
        ->and(implode("\n", $report->errors))->toContain('Database connection failed');
});

it('blocks queued upgrades when the migration lock path is not writable', function (): void {
    config(['queue.default' => 'array']);

    $originalStoragePath = storage_path();
    $storagePath = tempnam(sys_get_temp_dir(), 'capell-upgrade-readiness-');

    app()->useStoragePath($storagePath);

    try {
        $report = BuildUpgradeReadinessReportAction::run();
    } finally {
        app()->useStoragePath($originalStoragePath);
        if (is_string($storagePath) && file_exists($storagePath)) {
            unlink($storagePath);
        }
    }

    expect($report->result)->toBe(UpgradeReadinessResult::ManualRequired)
        ->and(implode("\n", $report->errors))->toContain('Migration lock path is not writable');
});
