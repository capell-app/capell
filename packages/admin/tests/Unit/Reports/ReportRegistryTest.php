<?php

declare(strict_types=1);

use Capell\Admin\Data\Reports\ReportDefinitionData;
use Capell\Admin\Data\Reports\ReportMetricData;
use Capell\Admin\Data\Reports\ReportSnapshotData;
use Capell\Admin\Filament\Pages\Reports\CacheFreshnessReport;
use Capell\Admin\Filament\Pages\Reports\ContentIntegrityReport;
use Capell\Admin\Filament\Pages\Reports\PublishingReadinessReport;
use Capell\Admin\Filament\Pages\Reports\SiteLanguageCoverageReport;
use Capell\Admin\Support\Reports\ReportRegistry;

uses()
    ->group('admin', 'reports');

it('replaces duplicate report keys with the latest definition', function (): void {
    $registry = new ReportRegistry;

    $registry->register(new ReportDefinitionData(
        key: 'core.content_integrity',
        label: 'Original',
        description: 'Original report',
        package: 'capell-app/admin',
        category: 'Content',
        pageClass: ContentIntegrityReport::class,
    ));

    $registry->register(new ReportDefinitionData(
        key: 'core.content_integrity',
        label: 'Replacement',
        description: 'Replacement report',
        package: 'capell-app/admin',
        category: 'Content',
        pageClass: SiteLanguageCoverageReport::class,
    ));

    expect($registry->get('core.content_integrity')?->label)->toBe('Replacement')
        ->and($registry->pageClasses())->toBe([SiteLanguageCoverageReport::class]);
});

it('sorts reports and extracts unique page classes', function (): void {
    $registry = new ReportRegistry;

    $registry->register(new ReportDefinitionData(
        key: '',
        label: 'Ignored',
        description: 'Ignored report',
        package: 'capell-app/admin',
        category: 'Content',
        pageClass: ContentIntegrityReport::class,
    ));

    $registry->register(new ReportDefinitionData(
        key: 'extension.cache',
        label: 'Extension Cache',
        description: 'Extension cache report',
        package: 'capell-app/extension',
        category: 'Operations',
        pageClass: CacheFreshnessReport::class,
        navigationSort: 10,
    ));

    $registry->register(new ReportDefinitionData(
        key: 'admin.workflow',
        label: 'Workflow',
        description: 'Workflow report',
        package: 'capell-app/admin',
        category: 'Workflow',
        pageClass: PublishingReadinessReport::class,
        navigationSort: 20,
    ));

    $registry->register(new ReportDefinitionData(
        key: 'admin.content',
        label: 'Content',
        description: 'Content report',
        package: 'capell-app/admin',
        category: 'Content',
        pageClass: ContentIntegrityReport::class,
        navigationSort: 50,
    ));

    $registry->register(new ReportDefinitionData(
        key: 'admin.content_later',
        label: 'Content Later',
        description: 'Content report with the same page class',
        package: 'capell-app/admin',
        category: 'Content',
        pageClass: ContentIntegrityReport::class,
        navigationSort: 60,
    ));

    expect(array_keys($registry->all()))->toBe([
        'extension.cache',
        'admin.workflow',
        'admin.content',
        'admin.content_later',
    ])->and($registry->pageClasses())->toBe([
        CacheFreshnessReport::class,
        PublishingReadinessReport::class,
        ContentIntegrityReport::class,
    ]);

    $registry->clear();

    expect($registry->all())->toBe([])
        ->and($registry->pageClasses())->toBe([]);
});

it('resolves report definition labels and snapshot state', function (): void {
    $translatedDefinition = new ReportDefinitionData(
        key: 'core.content_integrity',
        label: 'capell-admin::reports.content_integrity_label',
        description: 'capell-admin::reports.content_integrity_description',
        package: 'capell-app/admin',
        category: 'Content',
        pageClass: ContentIntegrityReport::class,
    );

    $literalDefinition = new ReportDefinitionData(
        key: 'test.literal',
        label: 'Literal Report',
        description: 'Literal description.',
        package: 'capell-app/test',
        category: 'Test',
        pageClass: CacheFreshnessReport::class,
        capabilityTags: ['cache', 'operations'],
    );

    $snapshot = new ReportSnapshotData(
        key: 'test.literal',
        emptyState: 'Nothing to report.',
        metrics: [
            new ReportMetricData(label: 'Findings', value: '3'),
        ],
    );

    expect($translatedDefinition->settingsKey())->toBe('core.content_integrity')
        ->and($translatedDefinition->resolvedLabel())->toBe('Content Integrity')
        ->and($translatedDefinition->resolvedDescription())->toBe(__('capell-admin::reports.content_integrity_description'))
        ->and($literalDefinition->resolvedLabel())->toBe('Literal Report')
        ->and($literalDefinition->resolvedDescription())->toBe('Literal description.')
        ->and($literalDefinition->capabilityTags)->toBe(['cache', 'operations'])
        ->and($snapshot->isEmpty())->toBeFalse()
        ->and($snapshot->metrics[0]->description)->toBeNull();
});
