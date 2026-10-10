<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Contracts\Publishing\PublicationReadinessContributor;
use Capell\Core\Data\Publishing\PublicationReadinessCheckData;
use Capell\Core\Data\Publishing\PublicationReadinessContextData;
use Capell\Core\Models\Contracts\Publishable;
use Illuminate\Database\Eloquent\Model;
use Override;

final class TaggedReadinessContributor implements PublicationReadinessContributor
{
    #[Override]
    public function supports(Model&Publishable $record): bool
    {
        return true;
    }

    #[Override]
    public function checks(Model&Publishable $record, PublicationReadinessContextData $context): array
    {
        return [new PublicationReadinessCheckData('tagged.check')];
    }
}
