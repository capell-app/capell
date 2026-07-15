<?php

declare(strict_types=1);

namespace Capell\Core\Testing\Contracts;

use AssertionError;
use Capell\Core\Testing\Assertions\AssertsCacheInvalidation;
use Capell\Core\Testing\Assertions\AssertsExtensionManifest;
use Capell\Core\Testing\Assertions\AssertsPackageLifecycle;
use Capell\Core\Testing\Assertions\AssertsPublicOutputSafety;
use Capell\Core\Testing\Data\CompanionPackageContractData;
use Closure;

final class CompanionPackageContractSuite
{
    public function run(CompanionPackageContractData $contract): void
    {
        AssertsExtensionManifest::run($contract->manifestPath);
        AssertsPackageLifecycle::run(
            $contract->packageRoot,
            $contract->providerClass,
            $contract->migrations,
            $contract->lifecycleAssertion,
        );

        if ($contract->authorizationAssertion instanceof Closure && ($contract->authorizationAssertion)() !== true) {
            throw new AssertionError(sprintf('[authorization.protected-resource] %s: authorization assertion failed.', $contract->packageRoot));
        }

        AssertsCacheInvalidation::run($contract->packageRoot, $contract->cacheInvalidationAssertion);
        AssertsPublicOutputSafety::run($contract->packageRoot, $contract->publicRender);
    }
}
