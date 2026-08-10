<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Publishing;

use Capell\Core\Data\Publishing\PublicationLocaleStatusContextData;
use Capell\Core\Data\Publishing\PublicationLocaleStatusData;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPublicationLocaleStatusAction
{
    // AsFake is part of the Core action contract and keeps this action testable.
    use AsFake;
    use AsObject;

    public function handle(PublicationLocaleStatusContextData $context): PublicationLocaleStatusData
    {
        return new PublicationLocaleStatusData(
            recordId: $context->record->getKey(),
            recordType: $context->record->getMorphClass(),
            siteId: $context->site->getKey(),
            languageId: $context->language->getKey(),
            languageCode: (string) $context->language->code,
            visibilityState: $context->record->publishVisibilityState($context->now),
            evaluatedAt: $context->now,
        );
    }
}
