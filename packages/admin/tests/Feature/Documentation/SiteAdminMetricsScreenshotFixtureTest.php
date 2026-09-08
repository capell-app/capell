<?php

declare(strict_types=1);

use Capell\Core\Support\Metrics\MetricCollectorRegistry;
use Workbench\App\Providers\ScreenshotWorkbenchServiceProvider;
use Workbench\App\Support\SiteAdminMetricsScreenshotFixture;

it('registers screenshot metric definitions only for a configured screenshot workbench', function (?string $database, bool $expected): void {
    config(['screenshot.database' => $database]);
    $registry = new MetricCollectorRegistry(app());
    app()->instance(MetricCollectorRegistry::class, $registry);

    new ScreenshotWorkbenchServiceProvider(app())->boot();

    expect($registry->collectors())->toHaveCount($expected ? 1 : 0);

    if ($expected) {
        expect($registry->collectors()[0])->toBeInstanceOf(SiteAdminMetricsScreenshotFixture::class)
            ->and($registry->definitions())->toHaveCount(4);
    }
})->with([
    'ordinary workbench' => [null, false],
    'empty screenshot setting' => ['', false],
    'screenshot workbench' => ['/tmp/capell-screenshot.sqlite', true],
]);
