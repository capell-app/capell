<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Render;

use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Frontend\Contracts\PublicRenderDataContributor;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\PublicRenderDataContributionData;
use Capell\Frontend\Data\PublicRenderDataContributionMetadataData;
use Capell\Frontend\Data\PublicRenderDataContributionsData;
use Capell\Frontend\Support\Cache\SurrogateKeyNormalizer;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use WeakMap;

final class PublicRenderDataContributorRegistry
{
    /** @var array<string, PublicRenderDataContributor> */
    private array $contributors = [];

    /** @var WeakMap<FrontendRenderContextData, PublicRenderDataContributionsData> */
    private WeakMap $prepared;

    /** @var WeakMap<FrontendRenderContextData, PublicRenderDataContributionMetadataData> */
    private WeakMap $metadata;

    /** @var WeakMap<FrontendRenderContextData, array<string, PublicRenderDataContributor>> */
    private WeakMap $active;

    /**
     * @param  iterable<PublicRenderDataContributor>  $contributors
     */
    public function __construct(
        iterable $contributors,
        private readonly ExtensionContributionReceiptRegistry $receipts,
    ) {
        $this->prepared = new WeakMap;
        $this->metadata = new WeakMap;
        $this->active = new WeakMap;

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
        $this->receipts->recordFromContext(
            ExtensionContributionType::PublicRenderData,
            $key,
            $contributor::class,
            $contributor::class,
        );
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
        $surrogateKeys = [];

        foreach ($this->activeContributors($context) as $key => $contributor) {
            $contribution = $contributor->contribute($context);

            throw_unless($contribution instanceof PublicRenderDataContributionData, InvalidArgumentException::class, sprintf('Public render-data contributor [%s] returned an invalid contribution.', $key));

            $values[$key] = $contribution->value;
            $surrogateKeys = [...$surrogateKeys, ...$contribution->surrogateKeys];
        }

        $metadata = $this->metadata($context);

        $prepared = new PublicRenderDataContributionsData(
            values: $values,
            fingerprint: $metadata->fingerprint,
            surrogateKeys: SurrogateKeyNormalizer::normalize([...$metadata->surrogateKeys, ...$surrogateKeys]),
            cacheDependencies: $metadata->cacheDependencies,
        );

        $this->prepared[$context] = $prepared;

        return $prepared;
    }

    public function metadata(FrontendRenderContextData $context): PublicRenderDataContributionMetadataData
    {
        if (isset($this->metadata[$context])) {
            return $this->metadata[$context];
        }

        $fingerprintMaterial = [];
        $surrogateKeys = [];
        $cacheDependencies = [];

        foreach ($this->activeContributors($context) as $key => $contributor) {
            $declaredModelTypes = $this->validatedModelTypes($contributor, $key);

            $contribution = $contributor->metadata($context);
            throw_unless($contribution instanceof PublicRenderDataContributionMetadataData, InvalidArgumentException::class, sprintf('Public render-data contributor [%s] returned invalid metadata.', $key));

            $fingerprintMaterial[$key] = $contribution->fingerprint;
            $surrogateKeys = [...$surrogateKeys, ...$contribution->surrogateKeys];

            foreach ($contribution->cacheDependencies as $dependency) {
                throw_unless(in_array($dependency->modelType, $declaredModelTypes, true), InvalidArgumentException::class, sprintf('Public render-data contributor [%s] returned an undeclared cache dependency model [%s].', $key, $dependency->modelType));
                $cacheDependencies[$dependency->identity()] = $dependency;
            }
        }

        $metadata = new PublicRenderDataContributionMetadataData(
            fingerprint: hash('sha256', json_encode($fingerprintMaterial, JSON_THROW_ON_ERROR)),
            surrogateKeys: SurrogateKeyNormalizer::normalize($surrogateKeys),
            cacheDependencies: array_values($cacheDependencies),
        );

        $this->metadata[$context] = $metadata;

        return $metadata;
    }

    /** @return list<class-string<Model>> */
    public function cacheDependencyModelTypes(): array
    {
        $modelTypes = [];

        foreach ($this->all() as $contributor) {
            foreach ($this->validatedModelTypes($contributor, $contributor->key()) as $modelType) {
                $modelTypes[$modelType] = true;
            }
        }

        return array_keys($modelTypes);
    }

    /** @return list<class-string<Model>> */
    private function validatedModelTypes(PublicRenderDataContributor $contributor, string $key): array
    {
        $modelTypes = $contributor->cacheDependencyModelTypes();

        throw_unless(array_is_list($modelTypes), InvalidArgumentException::class, sprintf('Public render-data contributor [%s] must return a list of dependency model classes.', $key));

        foreach ($modelTypes as $modelType) {
            throw_unless(is_string($modelType) && is_a($modelType, Model::class, true), InvalidArgumentException::class, sprintf('Public render-data contributor [%s] declared invalid dependency model [%s].', $key, is_scalar($modelType) ? (string) $modelType : get_debug_type($modelType)));
        }

        return $modelTypes;
    }

    /** @return array<string, PublicRenderDataContributor> */
    private function activeContributors(FrontendRenderContextData $context): array
    {
        if (isset($this->active[$context])) {
            return $this->active[$context];
        }

        $active = [];
        foreach ($this->all() as $key => $contributor) {
            if ($contributor->supports($context)) {
                $active[$key] = $contributor;
            }
        }

        $this->active[$context] = $active;

        return $active;
    }
}
