<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Marketplace;

use Capell\Core\Contracts\Marketplace\ExtensionEntitlements;
use Capell\Core\Enums\ExtensionLicenceStatus;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\Marketplace\ExtensionCommercialPolicy;
use Capell\Core\Support\Marketplace\NullExtensionEntitlements;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Capell\Core\Support\TableName;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

final class EnforceExtensionAuthorisationAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly ExtensionCommercialPolicy $policy,
        private readonly ExtensionEntitlements $entitlements,
        private readonly RuntimeSchemaState $schema,
    ) {}

    public function handle(string $name): void
    {
        // Inert until the deployment provisions the issuer key; the runtime gate keeps the legacy rules.
        if (! $this->policy->enforcing()) {
            return;
        }

        $visited = [];
        $this->enforceTree($name, $visited);
    }

    /** @param array<string, true> $visited */
    private function enforceTree(string $name, array &$visited): void
    {
        if (isset($visited[$name])) {
            return;
        }

        $visited[$name] = true;
        $this->enforce($name);
        $package = CapellCore::getPackage($name);
        if ($package->getKind() === 'bundle') {
            foreach ($package->getRequirements() as $member) {
                $this->enforceTree($member, $visited);
            }
        }
    }

    private function enforce(string $name): void
    {
        if (TrustedCorePackages::contains($name)) {
            return;
        }

        $requiresAuthorisation = $this->policy->requiresAuthorisation($name);
        $extension = null;
        try {
            if ($this->schema->hasTableOrFail(TableName::resolve('capell_extensions'))) {
                $extension = CapellExtension::query()->where('composer_name', $name)->first();
            }
        } catch (Throwable $throwable) {
            if ($requiresAuthorisation) {
                throw new RuntimeException(__('capell-core::extensions.authorisation.required', ['package' => $name]), $throwable->getCode(), previous: $throwable);
            }
        }

        if (! $this->policy->requiresAuthorisation($name, $extension)) {
            return;
        }

        if ($extension instanceof CapellExtension
            && (in_array($extension->marketplace_runtime_status, ['revoked', 'invalid', 'unapproved', 'domain_mismatch', 'unverified'], true)
                || (($extension->is_paid_marketplace_extension || $extension->marketplace_runtime_status !== null) && ! $extension->marketplace_runtime_allowed))) {
            throw new RuntimeException(__('capell-core::extensions.authorisation.required', ['package' => $name]));
        }

        if ($this->policy->isLocalDevelopment($name)) {
            return;
        }

        if ($extension instanceof CapellExtension
            && in_array($extension->marketplace_runtime_status, ['active', 'expired'], true)
            && $extension->marketplace_runtime_allowed
            && VerifyExtensionActivationAction::run($extension)) {
            return;
        }

        $package = CapellCore::getPackage($name);
        $domain = parse_url((string) config('app.url'), PHP_URL_HOST);
        try {
            if (! $this->entitlements instanceof NullExtensionEntitlements && $package->slug !== null && is_string($domain) && $domain !== '') {
                $decision = $this->entitlements->licenceDecision($package->slug, 'install', $domain);
                $candidate = $extension ?? new CapellExtension(['composer_name' => $name, 'status' => ExtensionStatusEnum::Disabled]);
                $candidate->forceFill(['marketplace_signed_activation' => $decision->signedActivation]);

                if ($decision->canInstall && $decision->runtimeAllowed
                    && in_array($decision->licenceStatus, [ExtensionLicenceStatus::Active, ExtensionLicenceStatus::Purchased, ExtensionLicenceStatus::Free], true)
                    && VerifyExtensionActivationAction::run($candidate)) {
                    $candidate->forceFill([
                        'is_paid_marketplace_extension' => $decision->licenceStatus !== ExtensionLicenceStatus::Free,
                        'marketplace_runtime_status' => 'active',
                        'marketplace_runtime_allowed' => true,
                        'marketplace_activation_checked_at' => now(),
                    ])->save();

                    return;
                }
            }
        } catch (Throwable $throwable) {
            throw new RuntimeException(__('capell-core::extensions.authorisation.required', ['package' => $name]), $throwable->getCode(), previous: $throwable);
        }

        throw new RuntimeException(__('capell-core::extensions.authorisation.required', ['package' => $name]));
    }
}
