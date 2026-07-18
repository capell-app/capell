<?php

declare(strict_types=1);

use Capell\Admin\Data\Reports\ReportDefinitionData;
use Capell\Admin\Data\Reports\ReportMetricData;
use Capell\Admin\Data\Reports\ReportSnapshotData;
use Capell\Admin\Filament\Pages\Reports\PackageReadinessReport;
use Capell\Admin\Filament\Pages\Reports\PublicRenderSafetyReport;
use Capell\Admin\Filament\Pages\Reports\PublishingReadinessReport;
use Capell\Admin\Support\Reports\ReportRegistry;

uses()
    ->group('admin', 'reports');

it('replaces duplicate report keys with the latest definition', function (): void {
    $registry = new ReportRegistry;

    $registry->register(new ReportDefinitionData(
        key: 'core.public_render_safety',
        label: 'Original',
        description: 'Original report',
        package: 'capell-app/admin',
        category: 'Content',
        pageClass: PublicRenderSafetyReport::class,
    ));

    $registry->register(new ReportDefinitionData(
        key: 'core.public_render_safety',
        label: 'Replacement',
        description: 'Replacement report',
        package: 'capell-app/admin',
        category: 'Content',
        pageClass: PublishingReadinessReport::class,
    ));

    expect($registry->get('core.public_render_safety')?->label)->toBe('Replacement')
        ->and($registry->pageClasses())->toBe([PublishingReadinessReport::class]);
});

it('sorts reports and extracts unique page classes', function (): void {
    $registry = new ReportRegistry;

    $registry->register(new ReportDefinitionData(
        key: '',
        label: 'Ignored',
        description: 'Ignored report',
        package: 'capell-app/admin',
        category: 'Content',
        pageClass: PublicRenderSafetyReport::class,
    ));

    $registry->register(new ReportDefinitionData(
        key: 'extension.cache',
        label: 'Extension Cache',
        description: 'Extension cache report',
        package: 'capell-app/extension',
        category: 'Operations',
        pageClass: PackageReadinessReport::class,
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
        pageClass: PublicRenderSafetyReport::class,
        navigationSort: 50,
    ));

    $registry->register(new ReportDefinitionData(
        key: 'admin.content_later',
        label: 'Content Later',
        description: 'Content report with the same page class',
        package: 'capell-app/admin',
        category: 'Content',
        pageClass: PublicRenderSafetyReport::class,
        navigationSort: 60,
    ));

    expect(array_keys($registry->all()))->toBe([
        'extension.cache',
        'admin.workflow',
        'admin.content',
        'admin.content_later',
    ])->and($registry->pageClasses())->toBe([
        PackageReadinessReport::class,
        PublishingReadinessReport::class,
        PublicRenderSafetyReport::class,
    ]);

    $registry->clear();

    expect($registry->all())->toBe([])
        ->and($registry->pageClasses())->toBe([]);
});

it('resolves report definition labels and snapshot state', function (): void {
    $translatedDefinition = new ReportDefinitionData(
        key: 'core.public_render_safety',
        label: 'capell-admin::reports.public_render_safety_label',
        description: 'capell-admin::reports.public_render_safety_description',
        package: 'capell-app/admin',
        category: 'Content',
        pageClass: PublicRenderSafetyReport::class,
    );

    $literalDefinition = new ReportDefinitionData(
        key: 'test.literal',
        label: 'Literal Report',
        description: 'Literal description.',
        package: 'capell-app/test',
        category: 'Test',
        pageClass: PackageReadinessReport::class,
        capabilityTags: ['cache', 'operations'],
    );

    $snapshot = new ReportSnapshotData(
        key: 'test.literal',
        emptyState: 'Nothing to report.',
        metrics: [
            new ReportMetricData(label: 'Findings', value: '3'),
        ],
    );

    expect($translatedDefinition->settingsKey())->toBe('core.public_render_safety')
        ->and($translatedDefinition->resolvedLabel())->toBe('Public Render Safety')
        ->and($translatedDefinition->resolvedDescription())->toBe(__('capell-admin::reports.public_render_safety_description'))
        ->and($literalDefinition->resolvedLabel())->toBe('Literal Report')
        ->and($literalDefinition->resolvedDescription())->toBe('Literal description.')
        ->and($literalDefinition->capabilityTags)->toBe(['cache', 'operations'])
        ->and($snapshot->isEmpty())->toBeFalse()
        ->and($snapshot->metrics[0]->description)->toBeNull();
});
