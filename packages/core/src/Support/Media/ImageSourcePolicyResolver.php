<?php

declare(strict_types=1);

namespace Capell\Core\Support\Media;

use Capell\Core\Enums\ImageSourceType;
use Capell\Core\Settings\CoreSettings;
use Throwable;

final class ImageSourcePolicyResolver
{
    /**
     * @param  list<ImageSourceType|string>|string|ImageSourceType|null  $schemaSources
     * @param  list<ImageSourceType|string>|string|ImageSourceType|null  $blueprintSources
     * @param  list<ImageSourceType|string>|string|ImageSourceType|null  $globalSources
     * @return list<ImageSourceType>
     */
    public function allowedSources(
        string|array|ImageSourceType|null $schemaSources = null,
        string|array|ImageSourceType|null $blueprintSources = null,
        string|array|ImageSourceType|null $globalSources = null,
    ): array {
        foreach ([$schemaSources, $blueprintSources, $globalSources, $this->globalAllowedSources(), 'all'] as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $sources = ImageSourcePresets::resolve($candidate);

            if ($sources !== []) {
                return $sources;
            }
        }

        return ImageSourcePresets::resolve('all');
    }

    private function globalAllowedSources(): ?string
    {
        try {
            return resolve(CoreSettings::class)->allowed_image_sources;
        } catch (Throwable) {
            return null;
        }
    }
}
