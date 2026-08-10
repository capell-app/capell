<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Publishing;

use Capell\Core\Data\Publishing\PublicationReadinessCheckData;
use Capell\Core\Data\Publishing\PublicationReadinessContextData;
use Capell\Core\Models\Contracts\Publishable;
use Illuminate\Database\Eloquent\Model;

interface PublicationReadinessContributor
{
    public const TAG = 'capell.publication-readiness-contributor';

    /** @return list<PublicationReadinessCheckData> */
    public function checks(Model&Publishable $record, PublicationReadinessContextData $context): array;

    public function supports(Model&Publishable $record): bool;
}
