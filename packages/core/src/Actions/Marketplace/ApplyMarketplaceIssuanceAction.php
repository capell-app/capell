<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Marketplace;

use Capell\Core\Actions\RuntimeRefresh\RestartQueueWorkersAction;
use Capell\Core\Data\Marketplace\ExtensionHealthAlertData;
use Capell\Core\Data\Marketplace\MarketplaceIssuanceResultData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\Marketplace\CommercialCatalogueStore;
use Capell\Core\Support\TableName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Applies what the issuer delivered (heartbeat or offline bundle) to runtime state: a newer
 * signed catalogue, renewed version 2 receipts and runtime revocations. Everything is verified
 * before it is stored, and the runtime caches are flushed so the gate re-reads the new state.
 */
final class ApplyMarketplaceIssuanceAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly CommercialCatalogueStore $catalogues,
        private readonly VerifyExtensionActivationAction $activation,
        private readonly RuntimeSchemaState $schema,
    ) {}

    /**
     * @param  array<mixed>  $receipts  version 2 issuer receipts
     * @param  array<mixed>|null  $catalogue  issuer-signed classification catalogue
     * @param  array<int, ExtensionHealthAlertData>  $revocations  alerts already verified by the caller
     */
    public function handle(array $receipts, ?array $catalogue, array $revocations = []): MarketplaceIssuanceResultData
    {
        $catalogueAccepted = false;
        if ($catalogue !== null) {
            /** @var array<string, mixed> $catalogue */
            $catalogueAccepted = $this->catalogues->accept($catalogue);
        }

        $accepted = 0;
        $rejected = 0;
        $revoked = 0;

        if ($this->schema->hasTable(TableName::resolve('capell_extensions'))) {
            DB::transaction(function () use ($receipts, $revocations, &$accepted, &$rejected, &$revoked): void {
                foreach ($revocations as $alert) {
                    $revoked += $this->revoke($alert) ? 1 : 0;
                }

                foreach ($receipts as $receipt) {
                    $this->renew($receipt) ? $accepted++ : $rejected++;
                }
            });
        } else {
            $rejected = count($receipts);
        }

        if ($catalogueAccepted || $accepted > 0 || $revoked > 0) {
            $this->propagate($revoked > 0 || $catalogueAccepted);
        }

        return new MarketplaceIssuanceResultData(
            catalogueAccepted: $catalogueAccepted,
            catalogueRejected: $catalogue !== null && ! $catalogueAccepted,
            receiptsAccepted: $accepted,
            receiptsRejected: $rejected,
            revocationsApplied: $revoked,
        );
    }

    private function renew(mixed $receipt): bool
    {
        if (! is_array($receipt) || ! is_string($receipt['composer_name'] ?? null)) {
            return false;
        }

        /** @var array<string, mixed> $receipt */
        $extension = CapellExtension::query()
            ->whereRaw('LOWER(composer_name) = ?', [strtolower($receipt['composer_name'])])
            ->lockForUpdate()
            ->first();
        if (! $extension instanceof CapellExtension) {
            return false;
        }

        $activation = is_array($extension->marketplace_signed_activation) ? $extension->marketplace_signed_activation : [];
        $revokedAfter = is_string($activation['revoked_after'] ?? null) ? $activation['revoked_after'] : null;
        if (! $this->activation->verify($receipt, $extension->composer_name, $revokedAfter)) {
            return false;
        }

        // Never replace a held receipt with an older one.
        $held = VerifyExtensionActivationAction::receiptFrom($activation);
        if ($held !== null && is_string($held['issued_at'] ?? null) && is_string($receipt['issued_at'])
            && $this->timestamp($held['issued_at']) > $this->timestamp($receipt['issued_at'])) {
            return false;
        }

        $activation['installed_receipt_v2'] = $receipt;
        unset($activation['revoked_after']);
        $status = $extension->marketplace_runtime_status;
        $extension->forceFill([
            'marketplace_signed_activation' => $activation,
            'marketplace_runtime_status' => in_array($status, ['active', 'expired'], true) ? $status : 'active',
            'marketplace_runtime_allowed' => true,
            'marketplace_runtime_reason' => null,
            'marketplace_activation_checked_at' => now(),
        ])->save();

        return true;
    }

    private function revoke(ExtensionHealthAlertData $alert): bool
    {
        if (! $alert->runtimeDisabled || $alert->composerName === null || $alert->composerName === '') {
            return false;
        }

        $now = CarbonImmutable::now();
        if ($alert->expiresAt !== null && $this->timestamp($alert->expiresAt) <= $now->getTimestamp()) {
            return false;
        }

        $extension = CapellExtension::query()
            ->whereRaw('LOWER(composer_name) = ?', [strtolower($alert->composerName)])
            ->lockForUpdate()
            ->first();
        if (! $extension instanceof CapellExtension) {
            return false;
        }

        $activation = is_array($extension->marketplace_signed_activation) ? $extension->marketplace_signed_activation : [];
        // Receipts signed at or before this moment stop counting; a later receipt reinstates.
        $activation['revoked_after'] = $now->toIso8601String();
        $extension->forceFill([
            'marketplace_signed_activation' => $activation,
            'marketplace_runtime_status' => 'revoked',
            'marketplace_runtime_allowed' => false,
            'marketplace_runtime_reason' => $alert->alertId,
            'marketplace_activation_checked_at' => $now,
        ])->save();

        return true;
    }

    private function propagate(bool $restartWorkers): void
    {
        CapellCore::clearExtensionCache();
        CapellCore::flushOctaneState();

        if ($restartWorkers) {
            // Retained queue workers hold resolved gates; a restart makes them re-read the state.
            try {
                RestartQueueWorkersAction::run();
            } catch (Throwable $throwable) {
                report($throwable);
            }
        }
    }

    private function timestamp(string $value): int
    {
        try {
            return CarbonImmutable::parse($value)->getTimestamp();
        } catch (Throwable) {
            return 0;
        }
    }
}
