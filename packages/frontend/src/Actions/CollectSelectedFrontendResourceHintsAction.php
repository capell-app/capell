<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Data\Assets\FrontendResourceHintData;
use Capell\Frontend\Data\FrontendResourceContextData;
use Capell\Frontend\Support\Assets\ThemeResourceResolver;
use Lorisleiva\Actions\Concerns\AsObject;

final class CollectSelectedFrontendResourceHintsAction
{
    use AsObject;

    public function __construct(private readonly ThemeResourceResolver $resolver) {}

    /**
     * @param  array<int, mixed>  $widgetResourceUsages
     * @return array<int, FrontendResourceHintData>
     */
    public function handle(FrontendResourceContextData $context, array $widgetResourceUsages): array
    {
        $groups = $this->resolver->groups($context->theme);

        return collect($widgetResourceUsages)
            ->map(static fn (mixed $usage): mixed => data_get($usage, 'resourceGroup'))
            ->filter(static fn (mixed $key): bool => is_string($key) && isset($groups[$key]))
            ->unique()
            ->flatMap(static fn (string $key): array => $groups[$key]->hints)
            ->filter(static fn (mixed $hint): bool => $hint instanceof FrontendResourceHintData)
            ->values()
            ->all();
    }
}
