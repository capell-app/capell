<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\PublishMarketplaceComposerChangeAction;
use Capell\Marketplace\Contracts\MarketplaceComposerChangePublisher;
use Capell\Marketplace\Data\ExtensionAcquisitionData;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceComposerPublicationRequestData;
use Capell\Marketplace\Data\MarketplaceComposerPublicationResultData;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

function marketplacePublicationFixture(): array
{
    return [
        new ExtensionAcquisitionData(
            composerName: 'capell-app/seo-suite',
            versionConstraint: '^4.2',
            composerCommand: 'composer require capell-app/seo-suite:^4.2',
            repositoryUrl: 'https://github.test/capell/seo-suite',
            purchaseUrl: null,
            requiresDeployment: true,
        ),
        new ExtensionListingData(
            slug: 'seo-suite',
            name: 'SEO Suite',
            composerName: 'capell-app/seo-suite',
            kind: 'tool',
            description: null,
            priceCents: 0,
            isPaid: false,
            forkRepoUrl: null,
            productId: null,
        ),
        (new MarketplaceInstallAttempt)->forceFill(['id' => 42]),
    ];
}

function tagMarketplacePublicationPublisher(MarketplaceComposerChangePublisher $publisher, string $key = 'test.marketplace.publisher'): void
{
    app()->instance($key, $publisher);
    app()->tag([$key], MarketplaceComposerChangePublisher::TAG);
}

function publishMarketplaceFixture(): array
{
    [$acquisition, $listing, $attempt] = marketplacePublicationFixture();

    return resolve(PublishMarketplaceComposerChangeAction::class)->handle($acquisition, $listing, $attempt);
}

it('falls back to the composer command when no publisher contributes', function (): void {
    expect(publishMarketplaceFixture())->toBe([
        'status' => 'unavailable',
        'fallback' => 'composer_command',
    ]);
});

it('publishes a typed request and returns a pull request reference', function (): void {
    $publisher = new class implements MarketplaceComposerChangePublisher
    {
        private ?MarketplaceComposerPublicationRequestData $captured = null;

        public function publish(MarketplaceComposerPublicationRequestData $request): MarketplaceComposerPublicationResultData
        {
            $this->captured = $request;

            return new MarketplaceComposerPublicationResultData(pullRequestUrl: 'https://github.test/capell/pulls/42');
        }

        public function captured(): ?MarketplaceComposerPublicationRequestData
        {
            return $this->captured;
        }
    };
    tagMarketplacePublicationPublisher($publisher);

    expect(publishMarketplaceFixture())->toBe([
        'status' => 'published',
        'reference' => 'https://github.test/capell/pulls/42',
        'type' => 'pull_request',
    ])->and($publisher->captured())->toMatchArray([
        'operationId' => '42',
        'composerName' => 'capell-app/seo-suite',
        'versionConstraint' => '^4.2',
        'repositoryUrl' => 'https://github.test/capell/seo-suite',
        'label' => 'SEO Suite',
    ]);
});

it('returns a commit reference when no pull request was created', function (): void {
    tagMarketplacePublicationPublisher(new class implements MarketplaceComposerChangePublisher
    {
        public function publish(MarketplaceComposerPublicationRequestData $request): MarketplaceComposerPublicationResultData
        {
            return new MarketplaceComposerPublicationResultData(commitSha: 'abc123');
        }
    });

    expect(publishMarketplaceFixture())->toBe([
        'status' => 'published',
        'reference' => 'abc123',
        'type' => 'commit',
    ]);
});

it('uses the unavailable fallback when a publisher has no active model', function (): void {
    tagMarketplacePublicationPublisher(new class implements MarketplaceComposerChangePublisher
    {
        public function publish(MarketplaceComposerPublicationRequestData $request): MarketplaceComposerPublicationResultData
        {
            throw (new ModelNotFoundException)->setModel('DeploymentConnection');
        }
    });

    expect(publishMarketplaceFixture())->toMatchArray([
        'status' => 'unavailable',
        'fallback' => 'composer_command',
    ]);
});

it('rejects a publisher result without a pull request or commit', function (): void {
    tagMarketplacePublicationPublisher(new class implements MarketplaceComposerChangePublisher
    {
        public function publish(MarketplaceComposerPublicationRequestData $request): MarketplaceComposerPublicationResultData
        {
            return new MarketplaceComposerPublicationResultData;
        }
    });

    expect(publishMarketplaceFixture())->toBe([
        'status' => 'failed',
        'fallback' => 'composer_command',
        'failure_reason' => 'Deployment publisher did not return a pull request URL or commit SHA.',
    ]);
});

it('redacts and logs publisher failures before using the composer fallback', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->once()->with(
        'capell-marketplace: deployment composer publisher failed',
        [
            'composer_name' => 'capell-app/seo-suite',
            'error' => 'Deployment password=[redacted] Bearer [redacted] failed.',
        ],
    );
    Log::swap($logger);

    tagMarketplacePublicationPublisher(new class implements MarketplaceComposerChangePublisher
    {
        public function publish(MarketplaceComposerPublicationRequestData $request): MarketplaceComposerPublicationResultData
        {
            throw new RuntimeException('Deployment password=hunter2 Bearer ghp_secret_token failed.');
        }
    });

    expect(publishMarketplaceFixture())->toBe([
        'status' => 'failed',
        'fallback' => 'composer_command',
        'failure_reason' => 'Deployment password=[redacted] Bearer [redacted] failed.',
    ]);
});

it('skips invalid tagged values and selects the first valid contributor deterministically', function (): void {
    app()->instance('test.marketplace.invalid-publisher', new stdClass);
    app()->tag(['test.marketplace.invalid-publisher'], MarketplaceComposerChangePublisher::TAG);

    foreach (['first' => 'first123', 'second' => 'second456'] as $key => $commit) {
        tagMarketplacePublicationPublisher(new class($commit) implements MarketplaceComposerChangePublisher
        {
            public function __construct(private readonly string $commit) {}

            public function publish(MarketplaceComposerPublicationRequestData $request): MarketplaceComposerPublicationResultData
            {
                return new MarketplaceComposerPublicationResultData(commitSha: $this->commit);
            }
        }, 'test.marketplace.' . $key . '-publisher');
    }

    expect(publishMarketplaceFixture())->toMatchArray([
        'status' => 'published',
        'reference' => 'first123',
        'type' => 'commit',
    ]);
});
