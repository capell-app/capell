<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Contracts\MarketplaceComposerChangePublisher;
use Capell\Marketplace\Contracts\MarketplaceComposerRequirementRemover;
use Capell\Marketplace\Data\MarketplaceComposerRemovalRequestData;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Support\MarketplaceComposerChangePublisherRegistry;
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
 * Only a publisher that implements MarketplaceComposerRequirementRemover is
 * asked. A publisher that can only add requirements is never called here:
 * publish() adds a requirement, which is the opposite of what an uninstall
 * wants. The person is told the manual step instead.
 */
final class RemoveMarketplaceComposerRequirementAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly MarketplaceComposerChangePublisherRegistry $publishers,
    ) {}

    /**
     * @param  list<string>  $composerNames
     * @return array{status: 'not_needed'|'unavailable'|'published'|'failed', reference?: string, type?: string, failure_reason?: string}
     */
    public function handle(MarketplaceInstallAttempt $attempt, array $composerNames): array
    {
        $publisher = $this->publishers->first();

        // No deployment source is registered, so this site's own composer.json
        // is the record and the local removal already changed it.
        if (! $publisher instanceof MarketplaceComposerChangePublisher) {
            return ['status' => 'not_needed'];
        }

        if (! $publisher instanceof MarketplaceComposerRequirementRemover) {
            return ['status' => 'unavailable'];
        }

        try {
            $result = $publisher->remove(new MarketplaceComposerRemovalRequestData(
                operationId: (string) $attempt->getKey(),
                composerNames: $composerNames,
                label: $attempt->extension_name,
            ));
        } catch (Throwable $throwable) {
            $reason = $this->redactedText($throwable->getMessage());

            Log::warning('capell-marketplace: deployment requirement removal failed', [
                'composer_names' => $composerNames,
                'error' => $reason,
            ]);

            return [
                'status' => 'failed',
                'failure_reason' => $reason,
            ];
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

    private function redactedText(string $text): string
    {
        $redacted = RedactMarketplaceDiagnosticContextAction::run([
            'text' => $text,
        ]);

        return is_string($redacted['text'] ?? null) ? $redacted['text'] : '[redacted]';
    }
}
