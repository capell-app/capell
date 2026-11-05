<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Data\HeadMetadataData;
use Capell\Frontend\Support\Render\HeadMetadataRegistry;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildHeadMetadataAction
{
    use AsObject;

    public function __construct(private readonly HeadMetadataRegistry $registry) {}

    /**
     * @param  array<int, string>  $robots
     * @param  array<string, string|array<string, mixed>>  $legacy
     */
    public function handle(string $title, ?string $description, ?string $url, ?string $siteName, array $robots, bool $isError, array $legacy = []): HeadMetadataData
    {
        $description = strip_tags($description ?? '');
        $siteName = strip_tags($siteName ?? '');
        $url ??= '';
        if ($isError) {
            unset($legacy['robots']);
        }

        $values = $this->registry->apply(array_replace([
            'og:title' => $title,
            'og:description' => $description,
            'og:type' => 'website',
            'og:url' => $url,
            'og:site_name' => $siteName,
            'twitter:card' => 'summary',
            'twitter:title' => $title,
            'twitter:description' => $description,
            'robots' => $isError ? 'noindex' : ($robots === [] ? 'index, follow' : implode(', ', $robots)),
            'jsonld:WebSite' => ['@type' => 'WebSite', 'name' => $siteName],
            'jsonld:WebPage' => ['@type' => 'WebPage', 'name' => $title, 'description' => $description, 'url' => $url],
        ], $legacy));
        $graph = [$values['jsonld:WebSite'], $values['jsonld:WebPage']];
        unset($values['jsonld:WebSite'], $values['jsonld:WebPage']);

        return new HeadMetadataData($values, $graph);
    }
}
