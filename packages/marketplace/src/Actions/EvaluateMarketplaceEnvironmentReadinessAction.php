<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Support\Deployment\ReleaseRootWriteGuard;
use Capell\Core\Support\Hosting\MultiNodeTopologyGuard;
use Capell\Core\Support\Process\ProcessExecutionSupport;
use Capell\Core\Support\Process\RuntimeBinaryResolver;
use Capell\Marketplace\Data\MarketplaceEnvironmentReadinessData;
use Capell\Marketplace\Data\MarketplaceReadinessCheckData;
use Capell\Marketplace\Enums\MarketplaceInstallCapability;
use Capell\Marketplace\Enums\MarketplaceReadinessStatus;
use Capell\Marketplace\Support\MarketplaceComposerChangePublisherRegistry;
use Capell\Marketplace\Support\MarketplaceQueueTimeoutChain;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * The one place that answers whether this host can run an automated Marketplace
 * install, and what an operator does about it when it cannot.
 *
 * Host-level facts live here. Per-attempt facts (is this package already
 * installed, is a duplicate running) stay in the install preflight, which
 * prepends this evaluation rather than repeating it.
 */
final class EvaluateMarketplaceEnvironmentReadinessAction
{
    use AsFake;
    use AsObject;

    public const string CACHE_KEY = 'capell-marketplace:environment-readiness';

    public const string GENERATION_KEY = 'capell-marketplace:environment-readiness:generation';

    /**
     * Short enough that an operator who fixes php.ini sees it reflected while
     * they are still looking at the page, long enough that rendering a
     * catalogue does not re-probe the host for every card.
     */
    public const int CACHE_SECONDS = 60;

    /**
     * Where the hosting documentation lives. Remediation points at an anchor
     * within it, named for the check it belongs to.
     */
    public const string DOCS_PATH = 'docs/operations/marketplace-hosting.md';

    private const string OPERATION = 'Installing a Marketplace extension with Composer';

    /** @var list<string> */
    private const array RELEASE_ROOT_PATHS = ['composer.json', 'composer.lock', 'vendor'];

    public function __construct(
        private readonly ReleaseRootWriteGuard $releaseRootWriteGuard = new ReleaseRootWriteGuard,
        private readonly MultiNodeTopologyGuard $multiNodeTopologyGuard = new MultiNodeTopologyGuard,
        private readonly RuntimeBinaryResolver $binaryResolver = new RuntimeBinaryResolver,
    ) {}

    /**
     * Drop every cached evaluation. Used by tests and by anything that has just
     * changed the host in a way the operator should see immediately.
     */
    public static function forget(): void
    {
        // Bumping the generation retires every cached variant at once, which a
        // per-key forget cannot do without tracking the keys it created.
        Cache::forever(self::GENERATION_KEY, self::generation() + 1);
    }

    /**
     * The nullable arguments exist so a caller — and Task 2's binary resolver —
     * can supply already-known facts instead of re-probing the host. Left null,
     * every fact is probed.
     */
    public function handle(
        ?string $releaseRoot = null,
        ?bool $processExecutionAvailable = null,
        ?bool $phpBinaryResolvable = null,
        ?bool $composerBinaryResolvable = null,
    ): MarketplaceEnvironmentReadinessData {
        return Cache::remember(
            $this->cacheKey($releaseRoot, $processExecutionAvailable, $phpBinaryResolvable, $composerBinaryResolvable),
            self::CACHE_SECONDS,
            fn (): MarketplaceEnvironmentReadinessData => $this->evaluate(
                $releaseRoot,
                $processExecutionAvailable,
                $phpBinaryResolvable,
                $composerBinaryResolvable,
            ),
        );
    }

    private static function generation(): int
    {
        $generation = Cache::get(self::GENERATION_KEY, 0);

        return is_numeric($generation) ? (int) $generation : 0;
    }

    private function evaluate(
        ?string $releaseRoot,
        ?bool $processExecutionAvailable,
        ?bool $phpBinaryResolvable,
        ?bool $composerBinaryResolvable,
    ): MarketplaceEnvironmentReadinessData {
        $publisherRegistered = $this->deployPublisherRegistered();

        $checks = [
            $this->processExecutionCheck($processExecutionAvailable),
            $this->binaryCheck('php_binary', $phpBinaryResolvable ?? $this->binaryResolver->phpOrNull() !== null),
            $this->binaryCheck('composer_binary', $composerBinaryResolvable ?? $this->binaryResolver->composerOrNull() !== null),
            $this->releaseRootCheck($releaseRoot),
            $this->queueWorkerCheck(),
            $this->sharedCacheCheck(),
            $this->timeoutChainCheck(),
            $this->deployPublisherCheck($publisherRegistered),
        ];

        return new MarketplaceEnvironmentReadinessData(
            capability: $this->capabilityFor($checks, $publisherRegistered),
            checks: $checks,
        );
    }

    /**
     * @param  list<MarketplaceReadinessCheckData>  $checks
     */
    private function capabilityFor(array $checks, bool $publisherRegistered): MarketplaceInstallCapability
    {
        $failures = array_values(array_filter(
            $checks,
            static fn (MarketplaceReadinessCheckData $check): bool => $check->failed(),
        ));

        if ($failures === []) {
            return MarketplaceInstallCapability::Automated;
        }

        $misconfigured = array_filter(
            $failures,
            static fn (MarketplaceReadinessCheckData $check): bool => ! $check->byDesign,
        );

        if ($misconfigured !== []) {
            return MarketplaceInstallCapability::Blocked;
        }

        // Everything that failed did so because of a deliberate hosting shape. A
        // registered publisher turns that into an automated deploy-time install
        // rather than a manual one.
        return $publisherRegistered
            ? MarketplaceInstallCapability::AutomatedViaDeployPublisher
            : MarketplaceInstallCapability::ManualOnly;
    }

    private function processExecutionCheck(?bool $available): MarketplaceReadinessCheckData
    {
        if ($available ?? ProcessExecutionSupport::isAvailable()) {
            return $this->passed('process_execution');
        }

        return $this->failed('process_execution', byDesign: true);
    }

    private function binaryCheck(string $key, bool $resolvable): MarketplaceReadinessCheckData
    {
        if ($resolvable) {
            return $this->passed($key);
        }

        // Composer missing is not fatal: the operator may still have a Composer
        // of their own, and the manual install path only needs the instructions.
        return $key === 'composer_binary'
            ? $this->warned($key)
            : $this->failed($key, byDesign: true);
    }

    private function releaseRootCheck(?string $releaseRoot): MarketplaceReadinessCheckData
    {
        $reason = $this->releaseRootWriteGuard->refusalReason(
            operation: self::OPERATION,
            relativePaths: self::RELEASE_ROOT_PATHS,
            releaseRoot: $releaseRoot,
            requiresServerSideTooling: true,
        );

        if ($reason === null) {
            return $this->passed('release_root_writable');
        }

        return $this->failed(
            'release_root_writable',
            byDesign: $reason->isByDesign(),
            messageKey: 'release_root_writable_' . $reason->value,
        );
    }

    private function queueWorkerCheck(): MarketplaceReadinessCheckData
    {
        // Whether a worker is actually consuming the Marketplace queue is not
        // knowable until the worker heartbeat lands. Reporting an unverified
        // pass here would be the exact lie this readiness concept exists to
        // remove, so this stays a warning until then.
        return $this->warned('queue_worker');
    }

    private function sharedCacheCheck(): MarketplaceReadinessCheckData
    {
        try {
            $this->multiNodeTopologyGuard->assertCacheStoreIsShared(self::OPERATION);
        } catch (RuntimeException) {
            return $this->failed('shared_cache', byDesign: false);
        }

        return $this->passed('shared_cache');
    }

    private function timeoutChainCheck(): MarketplaceReadinessCheckData
    {
        $chain = MarketplaceQueueTimeoutChain::resolve();

        if ($chain->isSafe()) {
            return $this->passed('timeout_chain');
        }

        return $this->failed('timeout_chain', byDesign: false, replacements: [
            'connection' => $chain->connectionName,
            'retry_after' => $chain->retryAfterSeconds ?? 0,
            'job_timeout' => $chain->jobTimeoutSeconds,
            'composer_timeout' => $chain->composerTimeoutSeconds,
        ]);
    }

    private function deployPublisherCheck(bool $registered): MarketplaceReadinessCheckData
    {
        // Informational either way: a single-node VPS with no publisher is a
        // perfectly healthy host, and a publisher is what lets an immutable one
        // stay automated.
        return $this->passed('deploy_publisher', messageKey: $registered
            ? 'deploy_publisher_registered'
            : 'deploy_publisher_absent');
    }

    private function deployPublisherRegistered(): bool
    {
        return app(MarketplaceComposerChangePublisherRegistry::class)->first() !== null;
    }

    /** @param array<string, scalar> $replacements */
    private function passed(string $key, ?string $messageKey = null, array $replacements = []): MarketplaceReadinessCheckData
    {
        return new MarketplaceReadinessCheckData(
            key: $key,
            status: MarketplaceReadinessStatus::Pass,
            message: $this->message($messageKey ?? $key . '_pass', $replacements),
        );
    }

    /** @param array<string, scalar> $replacements */
    private function warned(string $key, ?string $messageKey = null, array $replacements = []): MarketplaceReadinessCheckData
    {
        return new MarketplaceReadinessCheckData(
            key: $key,
            status: MarketplaceReadinessStatus::Warn,
            message: $this->message($messageKey ?? $key . '_warn', $replacements),
            remediation: $this->message($key . '_remediation', $replacements),
            docsAnchor: $this->anchor($key),
        );
    }

    /** @param array<string, scalar> $replacements */
    private function failed(string $key, bool $byDesign, ?string $messageKey = null, array $replacements = []): MarketplaceReadinessCheckData
    {
        return new MarketplaceReadinessCheckData(
            key: $key,
            status: MarketplaceReadinessStatus::Fail,
            message: $this->message($messageKey ?? $key . '_fail', $replacements),
            remediation: $this->message($key . '_remediation', $replacements),
            docsAnchor: $this->anchor($key),
            byDesign: $byDesign,
        );
    }

    /** @param array<string, scalar> $replacements */
    private function message(string $messageKey, array $replacements = []): string
    {
        return (string) __('capell-marketplace::marketplace.readiness.checks.' . $messageKey, $replacements);
    }

    private function anchor(string $key): string
    {
        return str_replace('_', '-', $key);
    }

    private function cacheKey(
        ?string $releaseRoot,
        ?bool $processExecutionAvailable,
        ?bool $phpBinaryResolvable,
        ?bool $composerBinaryResolvable,
    ): string {
        return sprintf(
            '%s:%d:%s',
            self::CACHE_KEY,
            self::generation(),
            hash('xxh128', serialize([
                $releaseRoot,
                $processExecutionAvailable,
                $phpBinaryResolvable,
                $composerBinaryResolvable,
            ])),
        );
    }
}
