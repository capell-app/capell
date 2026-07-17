<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\ClearCachesAction;
use Capell\Core\Actions\Install\OrchestrateInstallAction;
use Capell\Core\Actions\Install\PreflightExtraPackagesAction;
use Capell\Core\Actions\Install\RunInstallAction;
use Capell\Core\Contracts\InstallOrchestrationHost;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\Install\InstallOrchestrationData;
use Capell\Core\Data\Install\InstallRunResultData;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Core\Support\Process\ProcessFactoryInterface;

it('coordinates the complete console install sequence through a presentation host', function (): void {
    $inputData = new InstallInputData(
        siteUrl: 'https://example.test',
        packages: ['capell-app/core'],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        extraPackages: [],
    );
    $reporter = new NullProgressReporter;
    $calls = [];
    $host = new class($calls) implements InstallOrchestrationHost
    {
        /** @param array<int, string> $calls */
        public function __construct(private array &$calls) {}

        public function prepareApplication(InstallInputData $inputData, ProgressReporter $reporter): void
        {
            $this->calls[] = 'prepare';
        }

        public function outputPlan(InstallInputData $inputData): void
        {
            $this->calls[] = 'plan';
        }

        public function upgradeFilament(): void
        {
            $this->calls[] = 'filament';
        }

        public function buildFrontendAssets(): void
        {
            $this->calls[] = 'npm';
        }

        public function removeInstaller(): void
        {
            $this->calls[] = 'remove';
        }

        public function reportManualChanges(): void
        {
            $this->calls[] = 'manual';
        }

        public function finalizeInstall(InstallInputData $inputData, InstallRunResultData $result): void
        {
            expect($result->doctorStatus)->toBe('passed');
            $this->calls[] = 'finalize';
        }
    };

    $preflight = new PreflightExtraPackagesAction(Mockery::mock(ProcessFactoryInterface::class));
    $runInstall = Mockery::mock(RunInstallAction::class);
    $runInstall->shouldReceive('handle')->once()->with($inputData, $reporter);
    $clearCaches = Mockery::mock(ClearCachesAction::class);
    $clearCaches->shouldReceive('handle')->once()->with(['all'], $reporter);

    new OrchestrateInstallAction($preflight, $runInstall, $clearCaches)->handle(
        $inputData,
        new InstallOrchestrationData(
            outputPlan: true,
            runNpmBuild: true,
            removeInstaller: true,
            cachesToClear: ['all'],
        ),
        $reporter,
        $host,
    );

    expect($calls)->toBe([
        'prepare',
        'plan',
        'filament',
        'npm',
        'remove',
        'manual',
        'finalize',
    ]);
});

it('skips optional console operations when they were not requested', function (): void {
    $inputData = new InstallInputData(
        siteUrl: 'https://example.test',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
    );
    $reporter = new NullProgressReporter;
    $host = Mockery::mock(InstallOrchestrationHost::class);
    $host->shouldReceive('prepareApplication')->once()->with($inputData, $reporter);
    $host->shouldNotReceive('outputPlan', 'buildFrontendAssets', 'removeInstaller');
    $host->shouldReceive('upgradeFilament', 'reportManualChanges')->once();
    $host->shouldReceive('finalizeInstall')
        ->once()
        ->with(
            $inputData,
            Mockery::on(fn (InstallRunResultData $result): bool => $result->doctorStatus === 'passed'),
        );

    $preflight = new PreflightExtraPackagesAction(Mockery::mock(ProcessFactoryInterface::class));
    $runInstall = Mockery::mock(RunInstallAction::class);
    $runInstall->shouldReceive('handle')->once()->with($inputData, $reporter);
    $clearCaches = Mockery::mock(ClearCachesAction::class);
    $clearCaches->shouldReceive('handle')->once()->with([], $reporter);

    new OrchestrateInstallAction($preflight, $runInstall, $clearCaches)->handle(
        $inputData,
        new InstallOrchestrationData(
            outputPlan: false,
            runNpmBuild: false,
            removeInstaller: false,
            cachesToClear: [],
        ),
        $reporter,
        $host,
    );
});
