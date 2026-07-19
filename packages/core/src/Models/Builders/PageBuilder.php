<?php

declare(strict_types=1);

namespace Capell\Core\Models\Builders;

use Aimeos\Nestedset\QueryBuilder;
use Capell\Core\Exceptions\UnauthorizedPublicationMutationException;
use Capell\Core\Models\Page;
use Capell\Core\Support\Publishing\PublicationDateGuard;
use Override;

/** @extends QueryBuilder<Page> */
final class PageBuilder extends QueryBuilder
{
    /** @param array<string, mixed> $values */
    #[Override]
    public function update(array $values): int
    {
        $publicationAttributes = array_values(array_intersect(
            array_keys($values),
            ['visible_from', 'visible_until'],
        ));

        if ($publicationAttributes !== [] && ! PublicationDateGuard::permitted()) {
            throw UnauthorizedPublicationMutationException::forAttributes(
                $this->getModel(),
                $publicationAttributes,
            );
        }

        return parent::update($values);
    }
}
