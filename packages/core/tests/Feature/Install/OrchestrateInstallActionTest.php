<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\ClearCachesAction;
use Capell\Core\Actions\Install\OrchestrateInstallAction;
use Capell\Core\Actions\Install\RunInstallAction;
use Capell\Core\Contracts\InstallOrchestrationHost;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\Install\InstallOrchestrationData;
use Capell\Core\Data\Install\InstallRunResultData;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Core\Support\Install\NullProgressReporter;

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

        #[Override]
        public function prepareApplication(InstallInputData $inputData, ProgressReporter $reporter): void
        {
            $this->calls[] = 'prepare';
        }

        #[Override]
        public function outputPlan(InstallInputData $inputData): void
        {
            $this->calls[] = 'plan';
        }

        #[Override]
        public function upgradeFilament(): void
        {
            $this->calls[] = 'filament';
        }

        #[Override]
        public function buildFrontendAssets(): void
        {
            $this->calls[] = 'npm';
        }

        #[Override]
        public function removeInstaller(): void
        {
            $this->calls[] = 'remove';
        }

        #[Override]
        public function reportManualChanges(): void
        {
            $this->calls[] = 'manual';
        }

        #[Override]
        public function finalizeInstall(InstallInputData $inputData, InstallRunResultData $result): void
        {
            expect($result->doctorStatus)->toBe('passed')->and($result->completedSteps)->toBe(['install-package:vendor/dependency', InstallPlan::STEP_REBUILD_RESOURCES]);
            $this->calls[] = 'finalize';
        }
    };

    $runInstall = Mockery::mock(RunInstallAction::class);
    $runInstall->shouldReceive('runWithResult')->once()->with($inputData, $reporter)->andReturn(new InstallRunResultData(selectedPackages: [], completedSteps: ['install-package:vendor/dependency'], doctorStatus: 'passed'));
    $clearCaches = Mockery::mock(ClearCachesAction::class);
    $clearCaches->shouldReceive('handle')->once()->with(['all'], $reporter);
    runBoundAction(
        OrchestrateInstallAction::class,
        new OrchestrateInstallAction($runInstall, $clearCaches),
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
            Mockery::on(fn (InstallRunResultData $result): bool => $result->doctorStatus === 'passed' && $result->completedSteps === ['install-package:vendor/dependency']),
        );

    $runInstall = Mockery::mock(RunInstallAction::class);
    $runInstall->shouldReceive('runWithResult')->once()->with($inputData, $reporter)->andReturn(new InstallRunResultData(selectedPackages: [], completedSteps: ['install-package:vendor/dependency'], doctorStatus: 'passed'));
    $clearCaches = Mockery::mock(ClearCachesAction::class);
    $clearCaches->shouldReceive('handle')->once()->with(['packages'], $reporter);
    runBoundAction(
        OrchestrateInstallAction::class,
        new OrchestrateInstallAction($runInstall, $clearCaches),
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

it('withholds finalisation when an explicitly requested frontend build fails', function (): void {
    $input = new InstallInputData(siteUrl: 'https://example.test', packages: ['capell-app/frontend'], languages: ['en'], demoContent: false, cachesToClear: [], generateSitemap: false, generateStaticSite: false);
    $reporter = new NullProgressReporter;
    $host = Mockery::mock(InstallOrchestrationHost::class);
    $host->shouldReceive('prepareApplication')->once()->with($input, $reporter);
    $host->shouldReceive('upgradeFilament')->once();
    $host->shouldReceive('buildFrontendAssets')->once()->andThrow(new RuntimeException('Frontend build failed'));
    $host->shouldNotReceive('finalizeInstall');
    $runInstall = Mockery::mock(RunInstallAction::class);
    $runInstall->shouldReceive('runWithResult')->once()->with($input, $reporter)->andReturn(new InstallRunResultData(['capell-app/frontend'], [], 'passed'));
    $clearCaches = Mockery::mock(ClearCachesAction::class);
    $clearCaches->shouldNotReceive('handle');

    expect(function () use ($runInstall, $clearCaches, $input, $reporter, $host): void {
        new OrchestrateInstallAction($runInstall, $clearCaches)->handle($input, new InstallOrchestrationData(outputPlan: false, runNpmBuild: true, removeInstaller: false, cachesToClear: []), $reporter, $host);
    })->toThrow(RuntimeException::class, 'Frontend build failed');
});
