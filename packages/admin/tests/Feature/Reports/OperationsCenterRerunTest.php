<?php

declare(strict_types=1);

use Capell\Admin\Actions\Reports\BuildDemoInstallHealthReportAction;
use Capell\Admin\Filament\Pages\Reports\DemoInstallHealthReport;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;

it('exposes a translated rerun action and timestamps each fresh snapshot', function (): void {
    $page = app(DemoInstallHealthReport::class);
    $method = new ReflectionMethod($page, 'getHeaderActions');
    $actions = $method->invoke($page);

    expect($actions)->toHaveCount(1)
        ->and($actions[0])->toBeInstanceOf(Action::class)
        ->and($actions[0]->getName())->toBe('rerun')
        ->and($actions[0]->getLabel())->toBe(__('capell-admin::reports.demo_install_health_rerun'));

    CarbonImmutable::setTestNow('2026-07-14 10:00:00');
    $first = BuildDemoInstallHealthReportAction::run();
    CarbonImmutable::setTestNow('2026-07-14 10:05:00');
    $second = BuildDemoInstallHealthReportAction::run();
    CarbonImmutable::setTestNow();

    expect($first->generatedAt->toIso8601String())->not->toBe($second->generatedAt->toIso8601String());
});
