<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Media;

use Capell\Core\Enums\Media\MediaCompositionCropBehaviour;
use Capell\Core\Enums\Media\MediaCompositionTemplateStatus;
use Capell\Core\Support\Media\MediaCompositionGuidanceRegistry;
use Filament\Forms\Components\Field;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Renders registered composition guidance above a media upload control.
 *
 * The guidance is attached as decorative schema content (aboveContent), not as
 * a field, so it is never hydrated, dehydrated or saved with media or content.
 */
final class MediaCompositionGuidancePresenter
{
    public function __construct(private readonly MediaCompositionGuidanceRegistry $registry) {}

    /**
     * @template TField of Field
     *
     * @param  TField  $field
     * @return TField
     */
    public function apply(Field $field, string $guidanceKey): Field
    {
        return $field->aboveContent(fn (): Htmlable => $this->render($guidanceKey));
    }

    public function render(string $guidanceKey): Htmlable
    {
        $guidance = $this->registry->find($guidanceKey);

        if ($guidance === null) {
            return new HtmlString(view('capell-admin::components.forms.media-composition-guidance', [
                'guidance' => null,
                'warning' => __('capell-admin::media.composition_guidance.status.unregistered'),
            ])->render());
        }

        $status = $this->registry->status($guidance);
        $preset = $this->registry->presetFor($guidance);
        $label = __($guidance->label);
        $url = $status->isAvailable() ? $this->registry->templateUrl($guidance) : null;

        if ($status->isAvailable() && $url === null) {
            $status = MediaCompositionTemplateStatus::Missing;
        }

        $position = $guidance->position;

        return new HtmlString(view('capell-admin::components.forms.media-composition-guidance', [
            'guidance' => $guidance,
            'heading' => __('capell-admin::media.composition_guidance.heading', ['label' => $label]),
            'dimensions' => $preset === null ? null : __('capell-admin::media.composition_guidance.dimensions', $preset),
            'instructions' => __($guidance->instructions),
            'cropDescription' => __($guidance->cropBehaviour === MediaCompositionCropBehaviour::Contain
                ? 'capell-admin::media.composition_guidance.crop_contain'
                : 'capell-admin::media.composition_guidance.crop_cover', ['position' => $position]),
            'layoutVariants' => $guidance->layoutVariants === [] ? null : __('capell-admin::media.composition_guidance.layout_variants', [
                'variants' => implode(', ', $guidance->layoutVariants),
            ]),
            'quietRegions' => array_map(
                fn ($region): string => __('capell-admin::media.composition_guidance.quiet_region', [
                    'label' => __($region->label),
                    'width' => $region->width,
                    'height' => $region->height,
                    'x' => $region->x,
                    'y' => $region->y,
                ]),
                $guidance->quietRegions,
            ),
            'downloadUrl' => $url,
            'downloadName' => basename($guidance->templatePath),
            'downloadAccessibleName' => $preset === null ? null : __('capell-admin::media.composition_guidance.download', [
                'label' => $label,
                'width' => $preset['width'],
                'height' => $preset['height'],
                'ratio' => $preset['ratio'],
                'version' => $guidance->templateVersion,
            ]),
            'warning' => $status->isAvailable() ? null : __('capell-admin::media.composition_guidance.status.' . $status->value),
            'status' => $status->value,
        ])->render());
    }
}
