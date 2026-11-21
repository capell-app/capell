<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\MarketplaceComposerRemovalRequestData;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceOperationType;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Support\MarketplaceComposerChangePublisherRegistry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Crypt;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class AuthorizeMarketplaceComposerRemovalAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly MarketplaceComposerChangePublisherRegistry $publishers) {}

    public function handle(MarketplaceInstallAttempt $attempt, ?Authenticatable $actor): ?string
    {
        if (! $actor instanceof Authenticatable || $actor !== auth()->user()) {
            return null;
        }

        try {
            $attempt = $attempt->fresh();
            if ($attempt === null || $attempt->operation !== MarketplaceOperationType::Uninstall
                || $attempt->status !== MarketplaceInstallIntentStatus::Queued) {
                return null;
            }

            $actorId = $actor->getAuthIdentifier();
            if ((! is_string($actorId) && ! is_int($actorId))
                || $attempt->user_id === null
                || (string) $attempt->user_id !== (string) $actorId) {
                return null;
            }

            $authorizers = $this->publishers->removalAuthorizers();
            if (count($authorizers) !== 1) {
                return null;
            }

            $request = MarketplaceComposerRemovalRequestData::forAttempt($attempt);
            if (! $request instanceof MarketplaceComposerRemovalRequestData) {
                return null;
            }

            $authorizer = $authorizers[0];
            $approval = $authorizer->authorize($request, $actor);

            return is_string($approval) && $approval !== ''
                ? Crypt::encryptString(json_encode([$authorizer::class, $request->operationId, $request->composerNames, (string) $actorId, $approval], JSON_THROW_ON_ERROR))
                : null;
        } catch (Throwable) {
            // Keep the local uninstall available. The worker reports the manual handoff.
            return null;
        }
    }
}
