<?php

declare(strict_types=1);

namespace Capell\Core\Support\ContentGraph;

use Capell\Core\Contracts\ContentGraph\ContentGraphExtractor;
use Illuminate\Container\Container as LaravelContainer;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;

final class ContentGraphRegistry
{
    public const string TAG = 'capell.content-graph.extractor';

    /** @var array<class-string<Model>, array<int, class-string<ContentGraphExtractor>>> */
    private array $extractors = [];

    private readonly bool $discoversTaggedExtractors;

    public function __construct(?Container $container = null)
    {
        $this->discoversTaggedExtractors = $container instanceof Container;
    }

    /** @param class-string<ContentGraphExtractor> $extractor */
    public function register(string $extractor): void
    {
        $sourceModel = $extractor::sourceModel();

        if (in_array($extractor, $this->extractors[$sourceModel] ?? [], true)) {
            return;
        }

        $this->extractors[$sourceModel][] = $extractor;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array<int, ContentGraphExtractor>
     */
    public function forModel(string $modelClass): array
    {
        $container = $this->discoversTaggedExtractors ? LaravelContainer::getInstance() : null;
        $extractors = [];

        foreach ($this->extractors[$modelClass] ?? [] as $extractor) {
            $extractors[$extractor] = $container?->make($extractor) ?? new $extractor;
        }

        if ($container instanceof LaravelContainer) {
            foreach ($container->tagged(self::TAG) as $extractor) {
                if ($extractor instanceof ContentGraphExtractor && $extractor::sourceModel() === $modelClass) {
                    $extractors[$extractor::class] ??= $extractor;
                }
            }
        }

        return array_values($extractors);
    }
}
