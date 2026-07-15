<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use Capell\Core\Models\Theme;
use Capell\Frontend\Contracts\FrontendResourceContributor;
use Capell\Frontend\Data\Assets\FrontendResourceContributionData;
use Capell\Frontend\Data\Assets\FrontendResourceData;
use Capell\Frontend\Data\Assets\PublicResourceSourceData;
use Capell\Frontend\Data\FrontendResourceContextData;
use Capell\Frontend\Support\View\PublicModelMeta;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ThemeMetaAssetContributor implements FrontendResourceContributor
{
    public function resources(FrontendResourceContextData $context): array
    {
        $theme = $context->theme;

        if (! $theme instanceof Theme) {
            return [];
        }

        $assets = Arr::wrap(PublicModelMeta::get($theme, 'assets'));

        return array_values(collect($assets)
            ->filter(static fn (mixed $asset): bool => is_string($asset) && $asset !== '')
            ->map(fn (string $asset): ?FrontendResourceContributionData => $this->contribution($asset, $context))
            ->filter()
            ->values()
            ->all());
    }

    private function contribution(string $asset, FrontendResourceContextData $context): ?FrontendResourceContributionData
    {
        if (Str::endsWith($asset, '.js') && ! ($context->runtime->usesLivewire || $context->runtime->usesAlpine || $context->runtime->usesIslands)) {
            return null;
        }

        try {
            $source = new PublicResourceSourceData($asset);
        } catch (InvalidArgumentException) {
            return null;
        }

        $handle = 'capell-app/theme-metadata:' . hash('xxh128', $source->path);
        $resource = Str::endsWith($source->path, '.js')
            ? FrontendResourceData::moduleScript($handle, 'capell-app/theme-metadata', $source)
            : FrontendResourceData::style($handle, 'capell-app/theme-metadata', $source);

        return new FrontendResourceContributionData($resource);
    }
}
