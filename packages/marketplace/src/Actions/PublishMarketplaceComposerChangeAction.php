<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\ExtensionAcquisitionData;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceComposerPublicationRequestData;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Support\MarketplaceComposerChangePublisherRegistry;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class PublishMarketplaceComposerChangeAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly MarketplaceComposerChangePublisherRegistry $publishers,
    ) {}

    /**
     * @return array{status: string, reference?: string, type?: string, failure_reason?: string, fallback?: string}
     */
    public function handle(ExtensionAcquisitionData $acquisition, ExtensionListingData $listing, MarketplaceInstallAttempt $attempt): array
    {
        $publisher = $this->publishers->first();

        if ($publisher === null) {
            return [
                'status' => 'unavailable',
                'fallback' => 'composer_command',
            ];
        }

        try {
            $request = new MarketplaceComposerPublicationRequestData(
                operationId: (string) $attempt->getKey(),
                composerName: $acquisition->composerName,
                versionConstraint: $acquisition->versionConstraint,
                repositoryUrl: $acquisition->repositoryUrl,
                label: $listing->name,
            );
            $result = $publisher->publish($request);
        } catch (ModelNotFoundException $throwable) {
            $reason = $this->redactedText($throwable->getMessage());

            Log::info('capell-marketplace: deployment composer publisher unavailable', [
                'composer_name' => $acquisition->composerName,
                'error' => $reason,
            ]);

            return [
                'status' => 'unavailable',
                'fallback' => 'composer_command',
                'failure_reason' => $reason,
            ];
        } catch (Throwable $throwable) {
            $reason = $this->redactedText($throwable->getMessage());

            Log::warning('capell-marketplace: deployment composer publisher failed', [
                'composer_name' => $acquisition->composerName,
                'error' => $reason,
            ]);

            return [
                'status' => 'failed',
                'fallback' => 'composer_command',
                'failure_reason' => $reason,
            ];
        }

        if (is_string($result->pullRequestUrl ?? null) && $result->pullRequestUrl !== '') {
            return [
                'status' => 'published',
                'reference' => $result->pullRequestUrl,
                'type' => 'pull_request',
            ];
        }

        if (is_string($result->commitSha ?? null) && $result->commitSha !== '') {
            return [
                'status' => 'published',
                'reference' => $result->commitSha,
                'type' => 'commit',
            ];
        }

        return [
            'status' => 'failed',
            'fallback' => 'composer_command',
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
