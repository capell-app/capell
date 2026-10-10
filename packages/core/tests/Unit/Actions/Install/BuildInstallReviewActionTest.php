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
        expect(str_contains($review->items['Required dependencies already downloaded'], 'vendor/review-required'))->toBe($freshInstall)
            ->and(in_array('install-package:vendor/review-required', array_column(InstallPlan::build($input), 'key'), true))->toBe($freshInstall);
        if ($freshInstall) {
            expect($review->items['Database'])->toContain('DELETE ALL TABLES AND DATA');
        }
    }
});

it('carries list-valued review entries as real lists so a sentence containing a semicolon stays whole', function (): void {
    CapellCore::clearPackages();
    $input = new InstallInputData(
        siteUrl: 'https://example.test',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        extraPackages: ['vendor/downloaded'],
    );

    $review = BuildInstallReviewAction::run($input, cachesToClear: ['views']);
    $downloads = $review->lists[__('capell-core::install.review.downloads')];

    expect($downloads)->toBe(['vendor/downloaded', __('capell-core::install.review.composer_dependencies')])
        ->and($review->lists[__('capell-core::install.review.after_install')])->toContain(__('capell-core::install.review.package_cache'))
        ->and($review->items[__('capell-core::install.review.downloads')])->toContain('vendor/downloaded');
});

it('counts requested downloads separately from downloaded packages and their dependencies', function (): void {
    CapellCore::clearPackages();
    CapellCore::registerPackage('vendor/selected');
    CapellCore::registerPackage('vendor/dependency');
    CapellCore::registerPackage('vendor/already-downloaded');
    CapellCore::getPackage('vendor/selected')->requirements = ['vendor/dependency'];
    $input = new InstallInputData(
        siteUrl: 'https://example.test:8443/blog',
        packages: ['vendor/selected'],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        extraPackages: ['vendor/new-download', 'vendor/already-downloaded'],
    );
    $review = BuildInstallReviewAction::run($input);
    expect($review->items[__('capell-core::install.review.summary')])->toContain('1 package downloads requested; 3 already downloaded packages')
        ->and($review->items[__('capell-core::install.review.packages')])->toContain('vendor/already-downloaded')
        ->and($review->items[__('capell-core::install.review.packages')])->not->toContain('vendor/dependency')
        ->and($review->items[__('capell-core::install.review.dependencies')])->toContain('vendor/dependency')
        ->and($review->items[__('capell-core::install.review.downloads')])->not->toContain('vendor/already-downloaded')
        ->and($review->items[__('capell-core::install.review.site')])->toBe('https://example.test:8443/blog');
});
