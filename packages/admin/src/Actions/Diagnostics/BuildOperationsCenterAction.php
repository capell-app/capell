<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Diagnostics;

use Capell\Admin\Actions\Reports\BuildDemoInstallHealthReportAction;
use Capell\Admin\Contracts\Reports\BuildsReportSnapshot;
use Capell\Admin\Data\Diagnostics\OperationsCenterData;
use Capell\Admin\Data\Reports\ReportFindingData;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildOperationsCenterAction
{
    use AsObject;

    /** @var list<string> */
    public const array CATEGORIES = [
        'queue',
        'cache',
        'storage',
        'package_compatibility',
        'schema_integrity',
        'admin_access',
        'public_route_health',
    ];

    public function __construct(
        private readonly ?BuildsReportSnapshot $buildHealthReport = null,
    ) {}

    public function handle(): OperationsCenterData
    {
        $snapshot = ($this->buildHealthReport ?? resolve(BuildDemoInstallHealthReportAction::class))->handle();
        $categories = array_fill_keys(self::CATEGORIES, []);

        foreach ($snapshot->findings as $finding) {
            $category = $this->categoryFor($finding);

            if ($category !== null) {
                $categories[$category][] = $finding;
            }
        }

        return new OperationsCenterData($snapshot, $categories);
    }

    private function categoryFor(ReportFindingData $finding): ?string
    {
        $id = $finding->id ?? '';

        return match (true) {
            str_starts_with($id, 'core.queue.') => 'queue',
            str_starts_with($id, 'core.cache.') => 'cache',
            str_starts_with($id, 'core.storage.'), str_starts_with($id, 'admin.storage-') => 'storage',
            str_starts_with($id, 'core.packages.'), str_starts_with($id, 'core.manifest-'), str_starts_with($id, 'package-doctor.') => 'package_compatibility',
            str_starts_with($id, 'core.schema.') => 'schema_integrity',
            str_starts_with($id, 'core.admin.'), str_starts_with($id, 'admin.settings.') => 'admin_access',
            str_starts_with($id, 'core.route.'), str_starts_with($id, 'core.page-urls.') => 'public_route_health',
            default => null,
        };
    }
}
