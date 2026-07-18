<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\RunInstallPreflightChecksAction;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\InstallMemoryLimit;
use Capell\Core\Tests\Support\Install\RecordingInstallProgressReporter;

beforeEach(function (): void {
    app()->instance(InstallMemoryLimit::class, new InstallMemoryLimit('512M'));
});

afterEach(function (): void {
    app()->forgetInstance(InstallMemoryLimit::class);
});

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

it('reports the stable capell error when the effective memory limit remains too low', function (): void {
    app()->instance(InstallMemoryLimit::class, new InstallMemoryLimit('128M'));

    expect(fn (): mixed => RunInstallPreflightChecksAction::run(
        new InstallInputData(
            siteUrl: 'https://example.test',
            packages: [],
            languages: ['en'],
            demoContent: false,
            cachesToClear: [],
            generateSitemap: false,
            generateStaticSite: false,
        ),
        new RecordingInstallProgressReporter,
    ))->toThrow(
        RuntimeException::class,
        'Capell installation requires PHP memory_limit of at least 512M; the current limit is 128M.',
    );
});
