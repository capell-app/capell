<?php

declare(strict_types=1);

use Capell\Admin\Actions\Diagnostics\BuildOperationsCenterAction;
use Capell\Admin\Contracts\Reports\BuildsReportSnapshot;
use Capell\Admin\Data\Reports\ReportFindingData;
use Capell\Admin\Data\Reports\ReportSnapshotData;
use Capell\Admin\Enums\Reports\ReportFindingSeverity;
use Capell\Admin\Filament\Pages\Reports\DemoInstallHealthReport;
use Carbon\CarbonImmutable;

it('groups every operations category behind stable finding contracts', function (): void {
    $findings = [
        operationsFinding('core.queue.connection-configured'),
        operationsFinding('core.cache.store-configured'),
        operationsFinding('core.storage.writable'),
        operationsFinding('core.packages.installed'),
        operationsFinding('core.schema.required'),
        operationsFinding('core.admin.access'),
        operationsFinding('core.route.homepage'),
    ];
    $snapshot = new ReportSnapshotData(
        key: 'core.demo_install_health',
        emptyState: 'Healthy',
        findings: $findings,
        generatedAt: CarbonImmutable::parse('2026-07-14 12:00:00'),
    );
    $report = new class($snapshot) implements BuildsReportSnapshot
    {
        public function __construct(private readonly ReportSnapshotData $snapshot) {}

        public function handle(): ReportSnapshotData
        {
            return $this->snapshot;
        }
    };

    $operations = new BuildOperationsCenterAction($report)->handle();

    expect(array_keys($operations->categories))->toBe(BuildOperationsCenterAction::CATEGORIES)
        ->and(collect($operations->categories)->flatten(1))->toHaveCount(7)
        ->and($operations->generatedAt->equalTo($snapshot->generatedAt))->toBeTrue();

    foreach ($operations->categories as $categoryFindings) {
        expect($categoryFindings)->toHaveCount(1);
        $finding = $categoryFindings[0];

        expect($finding->id)->not->toBeNull()
            ->and($finding->evidence)->not->toBeEmpty()
            ->and($finding->remediation)->not->toBeNull();
    }
});

it('lets the report page rerun the composed operations snapshot', function (): void {
    $first = app(DemoInstallHealthReport::class)->operationsCenter();
    CarbonImmutable::setTestNow(now()->addMinute());
    $second = app(DemoInstallHealthReport::class)->operationsCenter();
    CarbonImmutable::setTestNow();

    expect($first->generatedAt->lessThan($second->generatedAt))->toBeTrue();
});

function operationsFinding(string $id): ReportFindingData
{
    return new ReportFindingData(
        severity: ReportFindingSeverity::Critical,
        title: $id,
        description: 'The operation needs attention.',
        id: $id,
        remediation: 'Run the documented remediation.',
        evidence: ['check' => $id],
    );
}
