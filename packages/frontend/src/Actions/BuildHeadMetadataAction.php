<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Support\Security\PublicStructuredDataScrubber;
use Capell\Frontend\Data\HeadMetadataData;
use Capell\Frontend\Support\Render\HeadMetadataRegistry;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildHeadMetadataAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly HeadMetadataRegistry $registry,
        private readonly PublicStructuredDataScrubber $scrubber,
    ) {}

    /**
     * @param  array<int, string>  $robots
     * @param  array<string, string|array<string, mixed>>  $legacy
     */
    public function handle(string $title, ?string $description, ?string $url, ?string $siteName, array $robots, bool $isError, array $legacy = []): HeadMetadataData
    {
        $description = strip_tags($description ?? '');
        $siteName = strip_tags($siteName ?? '');
        $url ??= '';
        $legacyRobots = $legacy['robots'] ?? '';
        unset($legacy['robots']);
        $pageRobots = $isError ? 'noindex' : implode(', ', $robots);
        $mergedRobots = HeadMetadataRegistry::mergeRobots($pageRobots, is_string($legacyRobots) ? $legacyRobots : '');

        $values = $this->registry->apply(array_replace([
            'og:title' => $title,
            'og:description' => $description,
            'og:type' => 'website',
            'og:url' => $url,
            'og:site_name' => $siteName,
            'twitter:card' => 'summary',
            'twitter:title' => $title,
            'twitter:description' => $description,
            'robots' => $mergedRobots === '' ? 'index, follow' : $mergedRobots,
            'jsonld:WebSite' => ['@type' => 'WebSite', 'name' => $siteName],
            'jsonld:WebPage' => ['@type' => 'WebPage', 'name' => $title, 'description' => $description, 'url' => $url],
        ], $legacy));
        if ($url === '') {
            unset($values['og:url']);
            if (is_array($values['jsonld:WebPage'])) {
                unset($values['jsonld:WebPage']['url']);
            }
        }

        $graph = [$values['jsonld:WebSite'], $values['jsonld:WebPage']];
        unset($values['jsonld:WebSite'], $values['jsonld:WebPage']);

        // Contributors assemble these values, so the leak policy runs here rather
        // than trusting each one.
        $meta = $this->scrubber->scrubMeta(array_filter($values, is_string(...)));
        $graph = array_values(array_map(
            fn (mixed $node): array => is_array($node) ? $this->scrubber->scrubGraph($node) : [],
            $graph,
        ));

        return new HeadMetadataData($meta, array_values(array_filter($graph, static fn (array $node): bool => $node !== [])));
    }
}
