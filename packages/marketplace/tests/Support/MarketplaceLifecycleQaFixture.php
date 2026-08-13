<?php

declare(strict_types=1);

namespace Capell\Marketplace\Tests\Support;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Marketplace\Contracts\MarketplaceAuthenticatedComposerRunner;
use Capell\Marketplace\Contracts\MarketplaceComposerChangePublisher;
use Capell\Marketplace\Contracts\MarketplaceComposerRunner;
use Capell\Marketplace\Contracts\MarketplaceInstalledPackageVersionResolver;
use Capell\Marketplace\Data\MarketplaceComposerPublicationRequestData;
use Capell\Marketplace\Data\MarketplaceComposerPublicationResultData;
use Capell\Marketplace\Data\MarketplaceComposerResultData;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use RuntimeException;

final class MarketplaceLifecycleQaFixture implements MarketplaceAuthenticatedComposerRunner, MarketplaceComposerChangePublisher, MarketplaceComposerRunner, MarketplaceInstalledPackageVersionResolver
{
    public const string PACKAGE_NAME = 'capell-app/marketplace-lifecycle-qa-fixture';

    public const string INITIAL_VERSION = '1.0.0';

    public const string UPDATED_VERSION = '1.1.0';

    private ?CapellManifestData $initialManifest = null;

    private ?CapellManifestData $updatedManifest = null;

    private ?string $installedVersion = null;

    private bool $publisherAvailable = true;

    private bool $composerFails = false;

    private ?string $failingVersionConstraint = null;

    /** @var list<array{composer_name: string, version_constraint: string, timeout_seconds: int}> */
    private array $composerCalls = [];

    /** @var list<MarketplaceComposerPublicationRequestData> */
    private array $publicationRequests = [];

    public function configurePackage(
        CapellManifestData $initialManifest,
        CapellManifestData $updatedManifest,
    ): void {
        throw_if($initialManifest->name !== self::PACKAGE_NAME || $updatedManifest->name !== self::PACKAGE_NAME, RuntimeException::class, 'The lifecycle QA fixture manifests must use the fixture package name.');

        $this->initialManifest = $initialManifest;
        $this->updatedManifest = $updatedManifest;
        $this->installedVersion = null;
        $this->publisherAvailable = true;
        $this->composerFails = false;
        $this->failingVersionConstraint = null;
        $this->composerCalls = [];
        $this->publicationRequests = [];
    }

    public function setPublisherAvailable(bool $available): void
    {
        $this->publisherAvailable = $available;
    }

    public function setComposerFails(bool $fails): void
    {
        $this->composerFails = $fails;
    }

    public function failWhenVersionConstraint(string $versionConstraint): void
    {
        $this->failingVersionConstraint = $versionConstraint;
    }

    public function prettyVersion(string $composerName): ?string
    {
        return $composerName === self::PACKAGE_NAME ? $this->installedVersion : null;
    }

    public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData
    {
        $this->composerCalls[] = [
            'composer_name' => $composerName,
            'version_constraint' => $versionConstraint,
            'timeout_seconds' => $timeoutSeconds,
        ];

        if ($this->composerFails || $this->failingVersionConstraint === $versionConstraint) {
            return new MarketplaceComposerResultData(
                exitCode: 1,
                output: 'Lifecycle QA Composer fixture failed.',
                errorOutput: 'The deterministic Composer fixture was instructed to fail.',
            );
        }

        $this->installVersion($composerName, $this->versionForConstraint($versionConstraint));

        return new MarketplaceComposerResultData(
            exitCode: 0,
            output: sprintf('Lifecycle QA Composer fixture installed %s %s.', $composerName, $this->installedVersion),
            errorOutput: '',
        );
    }

    /** @param array<string, mixed> $composerAuth */
    public function requireWithComposerAuth(
        string $composerName,
        string $versionConstraint,
        int $timeoutSeconds,
        array $composerAuth,
    ): MarketplaceComposerResultData {
        throw_if($composerAuth !== ['bearer' => 'fixture-token'], RuntimeException::class, 'The lifecycle QA fixture received unexpected Composer authorization.');

        return $this->require($composerName, $versionConstraint, $timeoutSeconds);
    }

    public function publish(MarketplaceComposerPublicationRequestData $request): MarketplaceComposerPublicationResultData
    {
        if (! $this->publisherAvailable) {
            throw (new ModelNotFoundException)->setModel('DeploymentConnection');
        }

        $this->publicationRequests[] = $request;

        return new MarketplaceComposerPublicationResultData(
            commitSha: 'fixture-deployment-' . $request->operationId,
        );
    }

    /** @return list<array{composer_name: string, version_constraint: string, timeout_seconds: int}> */
    public function composerCalls(): array
    {
        return $this->composerCalls;
    }

    /** @return list<MarketplaceComposerPublicationRequestData> */
    public function publicationRequests(): array
    {
        return $this->publicationRequests;
    }

    private function versionForConstraint(string $versionConstraint): string
    {
        if (str_contains($versionConstraint, self::INITIAL_VERSION)) {
            return self::INITIAL_VERSION;
        }

        if (str_contains($versionConstraint, self::UPDATED_VERSION)) {
            return self::UPDATED_VERSION;
        }

        throw new RuntimeException(sprintf('Unexpected lifecycle QA version constraint [%s].', $versionConstraint));
    }

    private function installVersion(string $composerName, string $version): void
    {
        if ($composerName !== self::PACKAGE_NAME) {
            throw new RuntimeException(sprintf('Unexpected lifecycle QA package [%s].', $composerName));
        }

        $manifest = $version === self::UPDATED_VERSION ? $this->updatedManifest : $this->initialManifest;

        throw_unless($manifest instanceof CapellManifestData, RuntimeException::class, 'The lifecycle QA fixture has not been configured with manifests.');

        CapellCore::registerManifestPackage($manifest, $version);
        CapellCore::forcePackageInstalled($composerName);
        $this->installedVersion = $version;
    }
}
