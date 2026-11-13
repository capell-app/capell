<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Capabilities;

use Capell\Core\Data\Capabilities\FormReferenceData;

/**
 * The forms a site offers, for packages that map or react to form fields
 * without depending on the form package. Resolve it through CapabilityRegistry:
 * when no form package provides it, the null catalogue lists nothing.
 */
interface FormCatalogue
{
    /**
     * @return list<FormReferenceData>
     */
    public function forms(?int $siteId = null): array;

    public function find(int $formId): ?FormReferenceData;
}
