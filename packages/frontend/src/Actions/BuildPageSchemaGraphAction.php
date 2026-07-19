<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Data\SchemaGraphData;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Support\Render\SchemaGraphContributorRegistry;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static SchemaGraphData|null run(FrontendRenderContextData $context)
 */
final class BuildPageSchemaGraphAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly SchemaGraphContributorRegistry $contributors) {}

    public function handle(FrontendRenderContextData $context): ?SchemaGraphData
    {
        $nodes = [];

        foreach ($this->contributors->forContext($context) as $contributor) {
            foreach ($contributor->contribute($context) as $node) {
                if (! in_array($node, $nodes, true)) {
                    $nodes[] = $node;
                }
            }
        }

        return $nodes === [] ? null : new SchemaGraphData($nodes);
    }
}
