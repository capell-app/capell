<?php

declare(strict_types=1);

namespace Capell\Core\Support\Capabilities;

use Capell\Core\Contracts\Capabilities\FormCatalogue;
use Capell\Core\Data\Capabilities\FormReferenceData;
use Override;

/**
 * Used when no installed package provides form-catalogue.
 */
final class NullFormCatalogue implements FormCatalogue
{
    /**
     * @return list<FormReferenceData>
     */
    #[Override]
    public function forms(?int $siteId = null): array
    {
        return [];
    }

    #[Override]
    public function find(int $formId): ?FormReferenceData
    {
        return null;
    }
}
