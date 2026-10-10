<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions\Fragments;

use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolvePublicFragmentAssetVersionAction
{
    use AsFake;
    use AsObject;

    public function handle(Model $asset): string
    {
        $translation = $asset->relationLoaded('translation') ? $asset->getRelation('translation') : null;
        $assetAttributes = $asset->getAttributes();
        $translationAttributes = $translation instanceof Model ? $translation->getAttributes() : null;
        ksort($assetAttributes);
        if (is_array($translationAttributes)) {
            ksort($translationAttributes);
        }

        return hash('sha256', json_encode([
            'asset' => $assetAttributes,
            'translation' => $translationAttributes,
        ], JSON_THROW_ON_ERROR));
    }
}
