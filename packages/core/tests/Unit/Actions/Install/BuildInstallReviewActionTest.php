<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\BuildInstallReviewAction;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\InstallPlan;

it('reviews the same dependency set as the execution plan for normal and fresh installs', function (): void {
    CapellCore::clearPackages();
    CapellCore::registerPackage(name: 'vendor/review-required');
    CapellCore::registerPackage(name: 'vendor/review-addon');
    CapellCore::getPackage('vendor/review-addon')->requirements = ['vendor/review-required'];
    CapellCore::forcePackageInstalled('vendor/review-required');
    foreach ([false, true] as $freshInstall) {
        $input = new InstallInputData(
            siteUrl: 'https://example.test',
            packages: ['vendor/review-addon'],
            languages: ['en'],
            demoContent: false,
            cachesToClear: [],
            generateSitemap: false,
            generateStaticSite: false,
            freshInstall: $freshInstall,
        );
        $review = BuildInstallReviewAction::run($input);
        expect(str_contains($review->items['Packages to install or set up'], 'vendor/review-required'))->toBe($freshInstall)
            ->and(in_array('install-package:vendor/review-required', array_column(InstallPlan::build($input), 'key'), true))->toBe($freshInstall);
        if ($freshInstall) {
            expect($review->items['Database'])->toContain('DELETE ALL TABLES AND DATA');
        }
    }
});
