<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Contracts\Capabilities\FormCatalogue;
use Capell\Core\Data\Capabilities\FormReferenceData;
use Override;
use RuntimeException;

/** A provider whose package is broken: it cannot even be constructed. */
final class FailingCapabilityFormCatalogueFixture implements FormCatalogue
{
    public function __construct()
    {
        throw new RuntimeException('The form package is misconfigured.');
    }

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
