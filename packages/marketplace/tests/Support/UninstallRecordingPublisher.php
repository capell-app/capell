<?php

declare(strict_types=1);

namespace Capell\Marketplace\Tests\Support;

use Capell\Marketplace\Contracts\MarketplaceComposerChangePublisher;
use Capell\Marketplace\Data\MarketplaceComposerPublicationRequestData;
use Capell\Marketplace\Data\MarketplaceComposerPublicationResultData;
use Override;

/** A publisher that can only add requirements, recording every publish() call. */
final class UninstallRecordingPublisher implements MarketplaceComposerChangePublisher
{
    /** @var list<string> */
    public array $published = [];

    #[Override]
    public function publish(MarketplaceComposerPublicationRequestData $request): MarketplaceComposerPublicationResultData
    {
        $this->published[] = $request->composerName;

        return new MarketplaceComposerPublicationResultData(pullRequestUrl: 'https://example.test/pull/1');
    }
}
