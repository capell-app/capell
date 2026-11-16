<?php

declare(strict_types=1);

namespace Capell\Core\Testing\Contracts;

use AssertionError;
use Capell\Core\Testing\Assertions\AssertsCacheInvalidation;
use Capell\Core\Testing\Assertions\AssertsDeclaredThrottles;
use Capell\Core\Testing\Assertions\AssertsExtensionManifest;
use Capell\Core\Testing\Assertions\AssertsGuardedOutboundHttp;
use Capell\Core\Testing\Assertions\AssertsPackageLifecycle;
use Capell\Core\Testing\Assertions\AssertsPrivacyContributors;
use Capell\Core\Testing\Assertions\AssertsPublicOutputSafety;
use Capell\Core\Testing\Assertions\AssertsPublicViewSafety;
use Capell\Core\Testing\Data\CompanionPackageContractData;
use Closure;

/**
 * The contract every companion package meets. The manifest, lifecycle, outbound HTTP, public view, throttle and
 * personal-data privacy checks always run; a package opts out of one of the static checks only with a decision record (see
 * ContractOptOutRecords). The authorization, cache invalidation and rendered-output checks run when the package's
 * own test supplies the closure that exercises them.
 */
final class CompanionPackageContractSuite
{
    public function run(CompanionPackageContractData $contract): void
    {
        AssertsExtensionManifest::run($contract->manifestPath);
        $optOuts = ContractOptOutRecords::forPackage($contract->packageRoot);
        AssertsPackageLifecycle::run(
            $contract->packageRoot,
            $contract->providerClass,
            $contract->migrations,
            $contract->lifecycleAssertion,
        );

        if (! $optOuts->allows(AssertsDeclaredThrottles::CONTRACT_ID)) {
            AssertsDeclaredThrottles::run($contract->manifestPath);
        }

        if (! $optOuts->allows(AssertsGuardedOutboundHttp::CONTRACT_ID)) {
            AssertsGuardedOutboundHttp::run($contract->packageRoot, $contract->manifestPath);
        }

        if (! $optOuts->allows(AssertsPublicViewSafety::CONTRACT_ID)) {
            AssertsPublicViewSafety::run($contract->packageRoot);
        }

        if (! $optOuts->allows(AssertsPrivacyContributors::CONTRACT_ID)) {
            AssertsPrivacyContributors::run($contract->packageRoot);
        }

        if ($contract->authorizationAssertion instanceof Closure && ($contract->authorizationAssertion)() !== true) {
            throw new AssertionError(sprintf('[authorization.protected-resource] %s: authorization assertion failed.', $contract->packageRoot));
        }

        AssertsCacheInvalidation::run($contract->packageRoot, $contract->cacheInvalidationAssertion);
        AssertsPublicOutputSafety::run($contract->packageRoot, $contract->publicRender);
    }
}
