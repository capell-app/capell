<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\Media\MediaContract;
use Capell\Core\Enums\MediaConversionEnum;
use Capell\Frontend\Data\FrontendMediaHintData;
use Capell\Frontend\Data\FrontendRenderContextData;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class BuildFrontendMediaHintsAction
{
    use AsFake;
    use AsObject;

    /**
     * @return array<int, FrontendMediaHintData>
     */
    public function handle(FrontendRenderContextData $context): array
    {
        $media = $this->likelyLcpMedia($context);

        if (! $media instanceof MediaContract) {
            return [];
        }

        $preferredConversions = $this->preferredConversions();
        $imageSrcset = $this->imageSrcset($media);

        return [
            new FrontendMediaHintData(
                url: $media->getAvailableFullUrl($preferredConversions),
                mimeType: $this->preloadMimeType($media, $preferredConversions),
                width: $media->getWidth(),
                height: $media->getHeight(),
                mediaUrl: $media->getFullUrl(),
                imageSrcset: $imageSrcset,
                imageSizes: $imageSrcset !== null ? '100vw' : null,
            ),
        ];
    }

    private function imageSrcset(MediaContract $media): ?string
    {
        if ($media->hasResponsiveImages()) {
            $srcset = trim($media->getSrcset());

            return $srcset !== '' ? $srcset : null;
        }

        $srcset = collect(MediaConversionEnum::defaultDimensionsByConversionValue())
            ->filter(
                fn (array $dimensions, string $conversion): bool => $media->hasConversion($conversion),
            )
            ->map(
                fn (array $dimensions, string $conversion): string => sprintf(
                    '%s %dw',
                    $media->getFullUrl($conversion),
                    $dimensions['width'],
                ),
            )
            ->implode(', ');

        return $srcset !== '' ? $srcset : null;
    }

    /** @param list<string> $preferredConversions */
    private function preloadMimeType(MediaContract $media, array $preferredConversions): string
    {
        $selectedConversion = collect($preferredConversions)
            ->first(fn (string $conversion): bool => $media->hasConversion($conversion));

        if (! is_string($selectedConversion)) {
            return $media->getMimeType();
        }

        $format = MediaConversionEnum::from($selectedConversion)->format();

        return 'image/' . $format;
    }

    private function likelyLcpMedia(FrontendRenderContextData $context): ?MediaContract
    {
        $page = $context->page;

        if (! $page instanceof Model) {
            return null;
        }

        if (! $page->relationLoaded('image')) {
            return null;
        }

        $image = $page->getRelation('image');

        return $image instanceof MediaContract ? $image : null;
    }

    /**
     * @return array<int, string>
     */
    private function preferredConversions(): array
    {
        return [
            MediaConversionEnum::Large->value,
            MediaConversionEnum::Medium->value,
            MediaConversionEnum::Small->value,
            MediaConversionEnum::Thumbnail->value,
        ];
    }
}
