<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions\Fragments;

use Capell\Frontend\Data\Fragments\PublicFragmentReferenceData;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolvePublicFragmentCacheIdentityAction
{
    use AsFake;
    use AsObject;

    public function handle(PublicFragmentReferenceData $reference): string
    {
        $ownerContext = $reference->ownerContext;
        ksort($ownerContext);

        return json_encode([
            'owner' => $reference->owner,
            'formatVersion' => $reference->formatVersion,
            'pageableType' => $reference->pageableType,
            'pageableId' => $reference->pageableId,
            'siteId' => $reference->siteId,
            'languageId' => $reference->languageId,
            'contentVersion' => $reference->contentVersion,
            'ownerContext' => $ownerContext,
        ], JSON_THROW_ON_ERROR);
    }
}
