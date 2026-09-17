<?php

declare(strict_types=1);

namespace Capell\Core\Data\Media;

use Capell\Core\Enums\Media\MediaCompositionCropBehaviour;
use Spatie\LaravelData\Data;

/**
 * Optional authoring guidance for a media field: a downloadable composition
 * template sized to a configured crop preset, plus translated instructions.
 *
 * `label` and `instructions` are translation keys; consumers translate them at
 * render time. Guidance is authoring metadata only and never enters media or
 * content state.
 */
final class MediaCompositionGuidanceData extends Data
{
    /**
     * @param  list<string>  $layoutVariants
     * @param  list<MediaCompositionQuietRegionData>  $quietRegions
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $preset,
        public string $templatePath,
        public string $templateVersion,
        public string $instructions,
        public string $templateDisk = 'public',
        public array $layoutVariants = [],
        public array $quietRegions = [],
        public MediaCompositionCropBehaviour $cropBehaviour = MediaCompositionCropBehaviour::Cover,
        public string $position = 'center',
    ) {}

    public function templateExtension(): string
    {
        return strtolower(pathinfo($this->templatePath, PATHINFO_EXTENSION));
    }
}
