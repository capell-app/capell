<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\RunInstallPreflightChecksAction;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Tests\Support\Install\RecordingInstallProgressReporter;

it('runs environment checks before the install mutates the application', function (): void {
    $reporter = new RecordingInstallProgressReporter;

    RunInstallPreflightChecksAction::run(
        new InstallInputData(
            siteUrl: 'https://example.test',
            packages: [],
            languages: ['en'],
            demoContent: false,
            cachesToClear: [],
            generateSitemap: false,
            generateStaticSite: false,
        ),
        $reporter,
    );

    expect($reporter->lines)
        ->toContain('✓ PHP runtime and required extensions are available.')
        ->toContain('✓ Composer, cache, storage, and database paths are ready.')
        ->toContain('✓ Database driver configuration is available.')
        ->toContain('Preflight checks passed.');
});

it('blocks installation when the site URL is invalid', function (): void {
    expect(fn (): mixed => RunInstallPreflightChecksAction::run(
        new InstallInputData(
            siteUrl: 'not-a-url',
            packages: [],
            languages: ['en'],
            demoContent: false,
            cachesToClear: [],
            generateSitemap: false,
            generateStaticSite: false,
        ),
        new RecordingInstallProgressReporter,
    ))->toThrow(RuntimeException::class, 'Install preflight failed');
});
