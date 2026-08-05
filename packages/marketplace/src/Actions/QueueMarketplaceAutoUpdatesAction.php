<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Admin\Actions\Extensions\BuildExtensionUpdateReadinessAction;
use Capell\Admin\Data\Extensions\ExtensionUpdateReadinessData;
use Capell\Core\Enums\ExtensionAutoUpdatePolicyEnum;
use Capell\Core\Enums\ExtensionReleaseKindEnum;
use Capell\Core\Models\CapellExtension;
use Capell\Marketplace\Data\MarketplaceBulkUpdateResultData;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Decide which extensions may update themselves tonight, and queue those.
 *
 * Three sources have to agree before a package is touched without anyone
 * asking: the operator's own per-extension policy, the release-readiness
 * classification the admin package already computes, and — for the security
 * policy — an advisory the marketplace actually sent.
 *
 * This is only defensible because every queued update is health-checked and
 * rollback-protected. Nothing here should ever grow a path that queues an
 * update by a route that skips those.
 */
final class QueueMarketplaceAutoUpdatesAction
{
    use AsFake;
    use AsObject;

    public function handle(bool $dryRun = false): MarketplaceBulkUpdateResultData
    {
        $eligible = $this->eligibleComposerNames();

        if ($dryRun || $eligible === []) {
            return new MarketplaceBulkUpdateResultData(requestedCount: count($eligible));
        }

        return QueueMarketplaceBulkUpdateAction::run(
            composerNames: $eligible,
            actor: MarketplaceInstallActorData::system('marketplace-auto-update'),
            source: MarketplaceInstallSource::Scheduler,
        );
    }

    /** @return list<string> */
    public function eligibleComposerNames(): array
    {
        $policies = $this->policiesByComposerName();

        if ($policies === []) {
            return [];
        }

        $securityAdvisories = array_fill_keys(ResolveMarketplaceSecurityAdvisoriesAction::run(), true);
        $eligible = [];

        foreach (BuildExtensionUpdateReadinessAction::run() as $readiness) {
            $policy = $policies[$readiness->packageName] ?? null;

            if (! $policy instanceof ExtensionAutoUpdatePolicyEnum) {
                continue;
            }

            if (! $this->readinessOffersAnUpdate($readiness)) {
                continue;
            }

            $releaseKind = ExtensionReleaseKindEnum::between($readiness->currentVersion, $readiness->latestVersion);

            if ($policy->allows($releaseKind, isset($securityAdvisories[$readiness->packageName]))) {
                $eligible[] = $readiness->packageName;
            }
        }

        return $eligible;
    }

    /**
     * A readiness state that is not one of the ready ones is not a smaller
     * update, it is a reason to stay put: `blocked` means the marketplace said
     * no, `unknown` means nobody knows what the latest version is, and
     * `major_review` means a human is meant to look at it.
     *
     * `major_review` is deliberately not excluded here — the security policy is
     * allowed to take a major release, and that decision belongs to the policy
     * rather than to this filter. What is excluded is the absence of a
     * candidate version at all.
     */
    private function readinessOffersAnUpdate(ExtensionUpdateReadinessData $readiness): bool
    {
        if (in_array($readiness->state, ['blocked', 'unknown', 'none'], true)) {
            return false;
        }

        return $readiness->currentVersion !== null && $readiness->latestVersion !== null;
    }

    /** @return array<string, ExtensionAutoUpdatePolicyEnum> */
    private function policiesByComposerName(): array
    {
        return CapellExtension::query()
            ->where('auto_update_policy', '!=', ExtensionAutoUpdatePolicyEnum::None->value)
            ->pluck('auto_update_policy', 'composer_name')
            ->map(fn (mixed $policy): ?ExtensionAutoUpdatePolicyEnum => $policy instanceof ExtensionAutoUpdatePolicyEnum
                ? $policy
                : ExtensionAutoUpdatePolicyEnum::tryFrom((string) $policy))
            ->filter(fn (?ExtensionAutoUpdatePolicyEnum $policy): bool => $policy instanceof ExtensionAutoUpdatePolicyEnum)
            ->all();
    }
}
