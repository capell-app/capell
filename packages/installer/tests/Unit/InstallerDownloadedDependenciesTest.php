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

it('persists discovered dependency steps and resumes a failed hook without repeating completed lifecycles', function (): void {
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
    $calls = [];
    $failHook = true;
    RunInstallStepAction::mock()->shouldReceive('handle')->andReturnUsing(function (string $key) use (&$calls, &$failHook): RunInstallStepResultData {
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

        if ($key === InstallPlan::packageAfterInstallStepKey('vendor/layout-builder') && $failHook) {
            $failHook = false;
            throw new RuntimeException('Controlled after-install failure');
        }

        return new RunInstallStepResultData(resolvedUserId: null, packageMetadataRefreshed: true);
    });

    $next = $initialPlan[0]['key'];
    do {
        $result = AdvanceInstallerRunAction::run($installId, $next);
        if ($result->code === InstallerRunStepResultCode::ExecutionFailed) {
            break;
        }

        $next = $result->nextStep;
    } while ($next !== InstallPlan::STEP_CLEAR_CACHES && $next !== null);

    expect($result->exceptionMessage)->toBe('Controlled after-install failure')
        ->and($sessions->completedSteps($installId))->toContain(InstallPlan::packageInstallStepKey('vendor/layout-builder'))
        ->and($sessions->completedSteps($installId))->not->toContain(InstallPlan::packageAfterInstallStepKey('vendor/layout-builder'));
    $savedPlan = $sessions->plan($installId);
    $savedKeys = array_column($savedPlan, 'key');
    expect($savedKeys)->toContain(...array_column($initialPlan, 'key'))
        ->and($savedKeys)->toHaveCount(count(array_unique($savedKeys)))
        ->and($sessions->packageMetadataRefreshed($installId))->toBeTrue();

    $replay = AdvanceInstallerRunAction::run($installId, InstallPlan::packageInstallStepKey('vendor/layout-builder'));
    expect($replay->nextStep)->toBe(InstallPlan::packageAfterInstallStepKey('vendor/layout-builder'))
        ->and($replay->plan)->toBe($savedPlan);
    $next = $replay->nextStep;
    do {
        $result = AdvanceInstallerRunAction::run($installId, $next);
        $next = $result->nextStep;
    } while ($next !== InstallPlan::STEP_CLEAR_CACHES && $next !== null && $result->code !== InstallerRunStepResultCode::ExecutionFailed);

    expect($next)->toBe(InstallPlan::STEP_CLEAR_CACHES)
        ->and($sessions->plan($installId))->toBe($savedPlan)
        ->and(array_count_values($calls)[InstallPlan::packageInstallStepKey('vendor/layout-builder')])->toBe(1)
        ->and(array_count_values($calls)[InstallPlan::packageAfterInstallStepKey('vendor/layout-builder')])->toBe(2)
        ->and(array_count_values($calls)[InstallPlan::packageInstallStepKey('vendor/theme')])->toBe(1)
        ->and(array_search(InstallPlan::packageInstallStepKey('vendor/navigation'), $calls, true))->toBeLessThan(array_search(InstallPlan::packageInstallStepKey('vendor/theme'), $calls, true))
        ->and(array_search(InstallPlan::packageAfterInstallStepKey('vendor/navigation'), $calls, true))->toBeLessThan(array_search(InstallPlan::packageAfterInstallStepKey('vendor/theme'), $calls, true));
});
