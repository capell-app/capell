<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Render;

use Capell\Core\Models\Blueprint;
use Capell\Frontend\Contracts\SchemaGraphContributor;
use Capell\Frontend\Data\FrontendRenderContextData;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;

final class SchemaGraphContributorRegistry
{
    /** @var list<SchemaGraphContributor> */
    private array $contributors = [];

    private bool $taggedContributorsDiscovered = false;

    public function __construct(private readonly Container $container) {}

    public function register(SchemaGraphContributor $contributor): void
    {
        $this->contributors[] = $contributor;
    }

    /** @return list<SchemaGraphContributor> */
    public function forContext(FrontendRenderContextData $context): array
    {
        $this->discoverTaggedContributors();
        $blueprintKey = $this->blueprintKey($context);

        return array_values(array_filter(
            $this->contributors,
            static function (SchemaGraphContributor $contributor) use ($blueprintKey): bool {
                $keys = $contributor->blueprintKeys();

                return $keys === null
                    || ($blueprintKey !== null && in_array($blueprintKey, $keys, true));
            },
        ));
    }

    private function blueprintKey(FrontendRenderContextData $context): ?string
    {
        $page = $context->page;

        if (! $page instanceof Model || ! $page->relationLoaded('blueprint')) {
            return null;
        }

        $blueprint = $page->getRelation('blueprint');

        return $blueprint instanceof Blueprint ? $blueprint->key : null;
    }

    private function discoverTaggedContributors(): void
    {
        if ($this->taggedContributorsDiscovered) {
            return;
        }

        $this->taggedContributorsDiscovered = true;

        foreach ($this->container->tagged(SchemaGraphContributor::TAG) as $contributor) {
            if ($contributor instanceof SchemaGraphContributor) {
                $this->register($contributor);
            }
        }
    }
}
