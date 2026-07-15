<?php

declare(strict_types=1);

namespace Capell\Core\Testing\Contracts;

use AssertionError;
use Capell\Core\Testing\Assertions\AssertsCacheInvalidation;
use Capell\Core\Testing\Assertions\AssertsExtensionManifest;
use Capell\Core\Testing\Assertions\AssertsPackageLifecycle;
use Capell\Core\Testing\Assertions\AssertsPublicOutputSafety;
use Capell\Core\Testing\Data\CompanionPackageContractData;

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

        if ($contract->authorizationAssertion !== null && ($contract->authorizationAssertion)() !== true) {
            throw new AssertionError("[authorization.protected-resource] {$contract->packageRoot}: authorization assertion failed.");
        }

        AssertsCacheInvalidation::run($contract->packageRoot, $contract->cacheInvalidationAssertion);
        AssertsPublicOutputSafety::run($contract->packageRoot, $contract->publicRender);
    }
}
