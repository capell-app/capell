<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Render;

use Capell\Frontend\Contracts\PublicRenderDataContributor;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\PublicRenderDataContributionData;
use Capell\Frontend\Data\PublicRenderDataContributionsData;
use Capell\Frontend\Support\Cache\SurrogateKeyNormalizer;
use InvalidArgumentException;
use WeakMap;

/** @extends WeakMap<FrontendRenderContextData, PublicRenderDataContributionsData> */
final class PublicRenderDataContributorRegistry
{
    /** @var array<string, PublicRenderDataContributor> */
    private array $contributors = [];

    /** @var WeakMap<FrontendRenderContextData, PublicRenderDataContributionsData> */
    private WeakMap $prepared;

    /**
     * @param  iterable<PublicRenderDataContributor>  $contributors
     */
    public function __construct(iterable $contributors = [])
    {
        $this->prepared = new WeakMap;

        foreach ($contributors as $contributor) {
            $this->register($contributor);
        }
    }

    public function register(PublicRenderDataContributor $contributor): void
    {
        $key = $contributor->key();

        throw_if(preg_match('/\A[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?\z/', $key) !== 1, InvalidArgumentException::class, 'Public render-data contributor keys must be lowercase stable identifiers.');
        throw_if(isset($this->contributors[$key]), InvalidArgumentException::class, sprintf('Public render-data contributor [%s] is already registered.', $key));

        $this->contributors[$key] = $contributor;
    }

    /** @return array<string, PublicRenderDataContributor> */
    public function all(): array
    {
        $contributors = $this->contributors;
        ksort($contributors);

        return $contributors;
    }

    public function prepare(FrontendRenderContextData $context): PublicRenderDataContributionsData
    {
        if (isset($this->prepared[$context])) {
            return $this->prepared[$context];
        }

        $values = [];
        $fingerprintMaterial = [];
        $surrogateKeys = [];
        $cacheDependencies = [];

        foreach ($this->all() as $key => $contributor) {
            if (! $contributor->supports($context)) {
                continue;
            }

            $contribution = $contributor->contribute($context);

            throw_unless($contribution instanceof PublicRenderDataContributionData, InvalidArgumentException::class, sprintf('Public render-data contributor [%s] returned an invalid contribution.', $key));

            $values[$key] = $contribution->value;
            $fingerprintMaterial[$key] = $contribution->fingerprint;
            $surrogateKeys = [...$surrogateKeys, ...$contribution->surrogateKeys];

            foreach ($contribution->cacheDependencies as $dependency) {
                $cacheDependencies[$dependency->identity()] = $dependency;
            }
        }

        $prepared = new PublicRenderDataContributionsData(
            values: $values,
            fingerprint: hash('sha256', json_encode($fingerprintMaterial, JSON_THROW_ON_ERROR)),
            surrogateKeys: SurrogateKeyNormalizer::normalize($surrogateKeys),
            cacheDependencies: array_values($cacheDependencies),
        );

        $this->prepared[$context] = $prepared;

        return $prepared;
    }
}
