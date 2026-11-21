<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Contracts\MarketplaceComposerChangePublisher;
use Capell\Marketplace\Data\MarketplaceComposerRemovalRequestData;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceOperationType;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Support\MarketplaceComposerChangePublisherRegistry;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Take an uninstalled extension's requirement out of the deployment source.
 *
 * A site whose releases are built from a deployment repository keeps that
 * repository's composer.json as the record of what it runs. Removing the
 * package locally without changing it would have the next deploy install the
 * extension again, so after an uninstall the registered publisher is asked to
 * drop the requirement too.
 *
 * Only the issuer of an approval obtained at enqueue time is asked. Other
 * publishers leave the manual step visible to the operator.
 */
final class RemoveMarketplaceComposerRequirementAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly MarketplaceComposerChangePublisherRegistry $publishers,
    ) {}

    /**
     * @return array{status: 'not_needed'|'unavailable'|'published'|'failed', reference?: string, type?: string, failure_reason?: string}
     */
    public function handle(MarketplaceInstallAttempt $attempt, ?string $sealedApproval = null): array
    {
        try {
            $authorizers = $this->publishers->removalAuthorizers();
            if ($authorizers === [] && $sealedApproval === null
                && ! $this->publishers->first() instanceof MarketplaceComposerChangePublisher) {
                return ['status' => 'not_needed'];
            }
        } catch (Throwable) {
            return ['status' => 'unavailable'];
        }

        if ($sealedApproval === null || count($authorizers) !== 1
            || $attempt->operation !== MarketplaceOperationType::Uninstall
            || $attempt->status !== MarketplaceInstallIntentStatus::Succeeded
            || $attempt->user_id === null) {
            return ['status' => 'unavailable'];
        }

        $request = MarketplaceComposerRemovalRequestData::forAttempt($attempt);
        if (! $request instanceof MarketplaceComposerRemovalRequestData) {
            return ['status' => 'unavailable'];
        }

        $authorizer = $authorizers[0];
        try {
            $approval = json_decode(Crypt::decryptString($sealedApproval), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['status' => 'unavailable'];
        }

        if (! is_array($approval)
            || ($approval[0] ?? null) !== $authorizer::class
            || ($approval[1] ?? null) !== $request->operationId
            || ($approval[2] ?? null) !== $request->composerNames
            || ($approval[3] ?? null) !== (string) $attempt->user_id
            || ! is_string($approval[4] ?? null)
            || $approval[4] === '') {
            return ['status' => 'unavailable'];
        }

        try {
            $result = $authorizer->remove(new MarketplaceComposerRemovalRequestData(
                operationId: $request->operationId,
                composerNames: $request->composerNames,
                label: $request->label,
                approvalId: $approval[4],
            ));
        } catch (Throwable) {
            // Provider exceptions can contain approval identifiers or credentials.
            Log::warning('capell-marketplace: deployment requirement removal failed', [
                'composer_names' => $request->composerNames,
            ]);

            return ['status' => 'failed'];
        }

        if (is_string($result->pullRequestUrl) && $result->pullRequestUrl !== '') {
            return [
                'status' => 'published',
                'reference' => $result->pullRequestUrl,
                'type' => 'pull_request',
            ];
        }

        if (is_string($result->commitSha) && $result->commitSha !== '') {
            return [
                'status' => 'published',
                'reference' => $result->commitSha,
                'type' => 'commit',
            ];
        }

        return [
            'status' => 'failed',
            'failure_reason' => 'Deployment publisher did not return a pull request URL or commit SHA.',
        ];
    }
}
