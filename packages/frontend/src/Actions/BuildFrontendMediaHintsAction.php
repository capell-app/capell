<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\Media\MediaContract;
use Capell\Core\Enums\MediaConversionEnum;
use Capell\Frontend\Data\FrontendMediaHintData;
use Capell\Frontend\Data\FrontendRenderContextData;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsObject;

class BuildFrontendMediaHintsAction
{
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

        return [
            new FrontendMediaHintData(
                url: $media->getAvailableFullUrl($this->preferredConversions()),
                mimeType: $media->getMimeType(),
                width: $media->getWidth(),
                height: $media->getHeight(),
            ),
        ];
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
