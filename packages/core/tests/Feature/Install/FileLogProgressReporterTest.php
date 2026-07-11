<?php

declare(strict_types=1);

use Capell\Core\Support\Install\CacheProgressReporter;
use Capell\Core\Support\Install\FileLogProgressReporter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;

it('mirrors install progress into cache and a timestamped log file', function (): void {
    Date::setTestNow('2026-05-07 09:15:00');

    $installId = 'file-log-' . uniqid();
    $reporter = new FileLogProgressReporter($installId, new CacheProgressReporter($installId));

    $reporter->markRunning();
    $reporter->step('Prepare database');
    $reporter->report('Migrating tables');
    $reporter->error('Could not publish asset');
    $reporter->markFailed();

    expect($reporter->logPath())->toBe(storage_path(sprintf('logs/capell-install-%s.log', $installId)))
        ->and(File::exists($reporter->logPath()))->toBeTrue();

    $log = File::get($reporter->logPath());

    expect($log)->toContain('[2026-05-07T09:15:00+00:00] [STATUS] running')
        ->and($log)->toContain('[2026-05-07T09:15:00+00:00] [STEP] Prepare database')
        ->and($log)->toContain('[2026-05-07T09:15:00+00:00] [INFO] Migrating tables')
        ->and($log)->toContain('[2026-05-07T09:15:00+00:00] [ERROR] Could not publish asset')
        ->and($log)->toContain('[2026-05-07T09:15:00+00:00] [STATUS] failed')
        ->and(Cache::get(sprintf('capell.install.%s.status', $installId)))->toBe('failed')
        ->and(Cache::get(sprintf('capell.install.%s.output', $installId)))->toContain('Prepare database')
        ->and(Cache::get(sprintf('capell.install.%s.output', $installId)))->toContain('Could not publish asset');
});
