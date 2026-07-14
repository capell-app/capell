<?php

declare(strict_types=1);

use Capell\Admin\Actions\Reports\BuildDemoInstallHealthReportAction;
use Capell\Admin\Filament\Pages\Reports\DemoInstallHealthReport;
use Carbon\CarbonImmutable;

it('exposes a translated rerun action and timestamps each fresh snapshot', function (): void {
    $page = app(DemoInstallHealthReport::class);

    expect($page->reportRun)->toBe(0)
        ->and(__('capell-admin::reports.demo_install_health_rerun'))->toBe('Re-run checks');

    $page->rerun();

    expect($page->reportRun)->toBe(1);

    CarbonImmutable::setTestNow('2026-07-14 10:00:00');
    $first = BuildDemoInstallHealthReportAction::run();
    CarbonImmutable::setTestNow('2026-07-14 10:05:00');
    $second = BuildDemoInstallHealthReportAction::run();
    CarbonImmutable::setTestNow();

    expect($first->generatedAt->toIso8601String())->not->toBe($second->generatedAt->toIso8601String());
});
