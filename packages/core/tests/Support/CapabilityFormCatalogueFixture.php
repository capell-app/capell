<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Contracts\Capabilities\FormCatalogue;
use Capell\Core\Data\Capabilities\FormReferenceData;
use Override;

final class CapabilityFormCatalogueFixture implements FormCatalogue
{
    /**
     * @return list<FormReferenceData>
     */
    #[Override]
    public function forms(?int $siteId = null): array
    {
        return [new FormReferenceData(7, 'contact', 'Contact', $siteId, ['email' => 'Email'])];
    }

    #[Override]
    public function find(int $formId): ?FormReferenceData
    {
        return $formId === 7 ? $this->forms()[0] : null;
    }
}
