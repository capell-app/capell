<?php

declare(strict_types=1);

namespace Capell\Core\Data\EditorImpact;

use Spatie\LaravelData\Data;

final class EditorImpactPageData extends Data
{
    /**
     * @param  list<string>  $locales
     * @param  list<EditorImpactUrlData>  $urls
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $site,
        public readonly array $locales,
        public readonly array $urls,
    ) {}

    /**
     * Language names for the pages this item appears on, falling back to the codes when no names are known.
     *
     * @return list<string>
     */
    public function languageLabels(): array
    {
        $labels = array_values(array_unique(array_map(static fn (EditorImpactUrlData $url): string => $url->languageLabel(), $this->urls)));

        return $labels === [] ? $this->locales : $labels;
    }
}
