<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\RunInstallStepAction;
use Capell\Core\Data\Install\RunInstallStepResultData;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Installer\Actions\AdvanceInstallerRunAction;
use Capell\Installer\Enums\InstallerRunStepResultCode;
use Capell\Installer\Support\InstallerSessionRepository;

it('persists discovered dependencies and only replays completed steps while the run is active', function (bool $failHook): void {
    config(['cache.default' => 'array']);
    CapellCore::clearPackages();
    $input = new InstallInputData(
        siteUrl: 'https://example.test',
        packages: [],
        languages: ['en'],
        demoContent: true,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        seedDefaultData: true,
        extraPackages: ['vendor/theme', 'vendor/layout-builder'],
    );
    $installId = '75757575-7575-4575-a575-757575757575';
    $initialPlan = array_values(array_filter(InstallPlan::build($input), fn (array $step): bool => str_contains($step['key'], 'package:') || $step['key'] === InstallPlan::STEP_CLEAR_CACHES));
    $sessions = resolve(InstallerSessionRepository::class);
    $sessions->run($installId)->startBrowserSteps(inputData: $input, plan: $initialPlan, firstStepKey: $initialPlan[0]['key'], preflight: []);
    $layoutInstall = InstallPlan::packageInstallStepKey('vendor/layout-builder');
    $layoutAfterInstall = InstallPlan::packageAfterInstallStepKey('vendor/layout-builder');
    $calls = [];
    RunInstallStepAction::mock()->shouldReceive('handle')->andReturnUsing(function (string $key) use (&$calls, $failHook, $layoutAfterInstall): RunInstallStepResultData {
        $calls[] = $key;
        if (InstallPlan::isPackageRequireStep($key)) {
            foreach ([
                'vendor/block-library' => [],
                'vendor/layout-builder' => ['vendor/block-library'],
                'vendor/navigation' => [],
                'vendor/theme' => ['vendor/layout-builder', 'vendor/navigation'],
            ] as $name => $requirements) {
                CapellCore::registerPackage(name: $name, setupCommand: 'fixture:setup', installCommand: 'fixture:install');
                $package = CapellCore::getPackage($name);
                $package->requirements = $requirements;
                $package->demoCommand = 'fixture:demo';
                $package->afterInstallCommand = 'fixture:after';
                CapellCore::forcePackageInstalled($name, false);
            }
        }

        if (InstallPlan::isPackageInstallStep($key)) {
            $name = InstallPlan::packageNameFromStep($key);
            foreach (CapellCore::getPackage($name)->getRequirements() as $required) {
                throw_unless(CapellCore::isPackageInstalled($required), RuntimeException::class, 'Missing required package: ' . $required);
            }

            CapellCore::forcePackageInstalled($name);
        }

        throw_if($key === $layoutAfterInstall && $failHook, RuntimeException::class, 'Controlled after-install failure');

        return new RunInstallStepResultData(resolvedUserId: null, packageMetadataRefreshed: true);
    });

    $next = $initialPlan[0]['key'];
    do {
        $step = $next;
        $result = AdvanceInstallerRunAction::run($installId, $step);
        $next = $result->nextStep;

        if ($step === $layoutInstall) {
            $replay = AdvanceInstallerRunAction::run($installId, $layoutInstall);
            expect($replay->code)->toBe(InstallerRunStepResultCode::Running)
                ->and($replay->nextStep)->toBe($next)
                ->and($replay->plan)->toBe($sessions->plan($installId));
        }
    } while ($next !== InstallPlan::STEP_CLEAR_CACHES && $next !== null);

    $savedPlan = $sessions->plan($installId);
    $savedKeys = array_column($savedPlan, 'key');
    $completed = $sessions->completedSteps($installId);
    $executions = array_count_values($calls);
    expect($savedKeys)->toContain(...array_column($initialPlan, 'key'))
        ->and($savedKeys)->toHaveCount(count(array_unique($savedKeys)))
        ->and($sessions->packageMetadataRefreshed($installId))->toBeTrue()
        ->and($completed)->toContain($layoutInstall)
        ->and($executions[$layoutInstall] ?? 0)->toBe(1)
        ->and($executions[$layoutAfterInstall] ?? 0)->toBe(1)
        ->and($executions[InstallPlan::packageInstallStepKey('vendor/theme')] ?? 0)->toBe(1)
        ->and(array_search(InstallPlan::packageInstallStepKey('vendor/navigation'), $calls, true))->toBeLessThan(array_search(InstallPlan::packageInstallStepKey('vendor/theme'), $calls, true));

    if ($failHook) {
        expect($result->code)->toBe(InstallerRunStepResultCode::ExecutionFailed)
            ->and($result->exceptionMessage)->toBe('Controlled after-install failure')
            ->and($completed)->not->toContain($layoutAfterInstall);
        $originalCalls = $calls;
        foreach ([$layoutInstall, $layoutAfterInstall] as $step) {
            $replay = AdvanceInstallerRunAction::run($installId, $step);
            expect($replay->code)->toBe(InstallerRunStepResultCode::ExecutionFailed)
                ->and($replay->nextStep)->toBeNull();
        }

        expect($calls)->toBe($originalCalls)
            ->and($sessions->plan($installId))->toBe($savedPlan)
            ->and($sessions->completedSteps($installId))->toBe($completed);

        return;
    }

    expect($next)->toBe(InstallPlan::STEP_CLEAR_CACHES)
        ->and($completed)->toContain($layoutAfterInstall)
        ->and($calls)->toContain(
            InstallPlan::packageAfterInstallStepKey('vendor/navigation'),
            InstallPlan::packageAfterInstallStepKey('vendor/theme'),
        )->and(array_search(InstallPlan::packageAfterInstallStepKey('vendor/navigation'), $calls, true))
        ->toBeLessThan(array_search(InstallPlan::packageAfterInstallStepKey('vendor/theme'), $calls, true));
})->with([
    'successful continuation' => false,
    'terminal failure' => true,
]);

it('persists foundation dependency lifecycles and panel setup after an installer-only theme download', function (): void {
    config(['cache.default' => 'array']);
    CapellCore::clearPackages();
    $input = new InstallInputData(
        siteUrl: 'https://example.test',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        seedDefaultData: true,
        extraPackages: ['capell-app/theme-foundation'],
    );
    $installId = '76767676-7676-4676-a676-767676767676';
    $key = InstallPlan::packageRequireStepKey('capell-app/theme-foundation');
    $initialPlan = array_values(array_filter(InstallPlan::build($input), fn (array $step): bool => $step['key'] === $key || $step['key'] === InstallPlan::STEP_RUN_MIGRATIONS_POST));
    $sessions = resolve(InstallerSessionRepository::class);
    $sessions->run($installId)->startBrowserSteps(inputData: $input, plan: $initialPlan, firstStepKey: $key, preflight: []);
    RunInstallStepAction::mock()->shouldReceive('handle')->once()->andReturnUsing(function (): RunInstallStepResultData {
        foreach ([
            'capell-app/admin' => [],
            'capell-app/frontend' => [],
            'capell-app/theme-foundation' => ['capell-app/admin', 'capell-app/frontend'],
        ] as $name => $requirements) {
            CapellCore::registerPackage(name: $name, setupCommand: 'fixture:setup', installCommand: 'fixture:install');
            $package = CapellCore::getPackage($name);
            $package->requirements = $requirements;
            $package->afterInstallCommand = 'fixture:after';
        }

        return new RunInstallStepResultData(resolvedUserId: null, packageMetadataRefreshed: true);
    });

    $result = AdvanceInstallerRunAction::run($installId, $key);
    $keys = array_column($sessions->plan($installId), 'key');
    expect($result->code)->toBe(InstallerRunStepResultCode::Running)
        ->and($result->nextStep)->toBe(InstallPlan::STEP_INSTALL_FILAMENT_PANEL)
        ->and($keys)->toContain(
            InstallPlan::packageInstallStepKey('capell-app/admin'),
            InstallPlan::packageSetupStepKey('capell-app/admin'),
            InstallPlan::packageInstallStepKey('capell-app/frontend'),
            InstallPlan::packageAfterInstallStepKey('capell-app/frontend'),
        )->and($sessions->completedSteps($installId))->toBe([$key]);
});
