<?php

declare(strict_types=1);

namespace Capell\Marketplace\Tests\Support;

use Capell\Marketplace\Contracts\MarketplaceComposerRemovalAuthorizer;
use Capell\Marketplace\Data\MarketplaceComposerPublicationResultData;
use Capell\Marketplace\Data\MarketplaceComposerRemovalRequestData;
use Illuminate\Contracts\Auth\Authenticatable;
use Override;
use RuntimeException;

class UninstallRecordingAuthorizer implements MarketplaceComposerRemovalAuthorizer
{
    private const string ApprovalId = 'opaque-authorisation-lookup';

    /** @var array{actor: Authenticatable, request: MarketplaceComposerRemovalRequestData}|null */
    public ?array $issued = null;

    public ?MarketplaceComposerRemovalRequestData $removed = null;

    public bool $canAuthorize = true;

    public bool $canConsume = true;

    #[Override]
    public function authorize(MarketplaceComposerRemovalRequestData $request, Authenticatable $actor): ?string
    {
        $this->issued = ['actor' => $actor, 'request' => $request];

        return $this->canAuthorize ? self::ApprovalId : null;
    }

    #[Override]
    public function remove(MarketplaceComposerRemovalRequestData $request): MarketplaceComposerPublicationResultData
    {
        $this->removed = $request;

        throw_if(! $this->canConsume || $request->approvalId !== self::ApprovalId, RuntimeException::class, 'Approval unavailable: ' . self::ApprovalId);

        return new MarketplaceComposerPublicationResultData(pullRequestUrl: 'https://example.test/pull/2');
    }
}
