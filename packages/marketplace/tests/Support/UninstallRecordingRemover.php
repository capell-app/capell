<?php

declare(strict_types=1);

namespace Capell\Marketplace\Tests\Support;

use Capell\Marketplace\Contracts\MarketplaceComposerChangePublisher;
use Capell\Marketplace\Contracts\MarketplaceComposerRequirementRemover;
use Capell\Marketplace\Data\MarketplaceComposerPublicationRequestData;
use Capell\Marketplace\Data\MarketplaceComposerPublicationResultData;
use Capell\Marketplace\Data\MarketplaceComposerRemovalRequestData;
use Override;

/** A publisher that can also remove requirements, recording both kinds of call. */
final class UninstallRecordingRemover implements MarketplaceComposerChangePublisher, MarketplaceComposerRequirementRemover
{
    /** @var list<string> */
    public array $published = [];

    /** @var list<list<string>> */
    public array $removed = [];

    #[Override]
    public function publish(MarketplaceComposerPublicationRequestData $request): MarketplaceComposerPublicationResultData
    {
        $this->published[] = $request->composerName;

        return new MarketplaceComposerPublicationResultData(pullRequestUrl: 'https://example.test/pull/1');
    }

    #[Override]
    public function remove(MarketplaceComposerRemovalRequestData $request): MarketplaceComposerPublicationResultData
    {
        $this->removed[] = $request->composerNames;

        return new MarketplaceComposerPublicationResultData(pullRequestUrl: 'https://example.test/pull/2');
    }
}
