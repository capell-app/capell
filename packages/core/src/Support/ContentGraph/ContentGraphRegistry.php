<?php

declare(strict_types=1);

namespace Capell\Core\Support\ContentGraph;

use Capell\Core\Contracts\ContentGraph\ContentGraphExtractor;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;

final class ContentGraphRegistry
{
    public const string TAG = 'capell.content-graph.extractor';

    /** @var array<class-string<Model>, array<int, class-string<ContentGraphExtractor>>> */
    private array $extractors = [];

    private bool $taggedExtractorsDiscovered = false;

    public function __construct(private readonly ?Container $container = null) {}

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
        $this->discoverTaggedExtractors();

        return collect($this->extractors[$modelClass] ?? [])
            ->map(fn (string $extractor): ContentGraphExtractor => $this->container?->make($extractor) ?? new $extractor)
            ->all();
    }

    private function discoverTaggedExtractors(): void
    {
        if ($this->taggedExtractorsDiscovered || ! $this->container instanceof Container) {
            return;
        }

        foreach ($this->container->tagged(self::TAG) as $extractor) {
            if ($extractor instanceof ContentGraphExtractor) {
                $this->register($extractor::class);
            }
        }

        $this->taggedExtractorsDiscovered = true;
    }
}
