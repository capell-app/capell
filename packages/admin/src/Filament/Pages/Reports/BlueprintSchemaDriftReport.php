<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Reports;

use Capell\Admin\Actions\Reports\BuildBlueprintSchemaDriftReportAction;

final class BlueprintSchemaDriftReport extends AbstractCoreReportPage
{
    public const string REPORT_KEY = 'core.blueprint_schema_drift';

    protected const string REPORT_ACTION = BuildBlueprintSchemaDriftReportAction::class;

    protected static ?string $slug = 'reports/blueprint-schema-drift';
}
