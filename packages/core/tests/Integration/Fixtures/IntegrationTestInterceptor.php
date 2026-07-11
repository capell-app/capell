<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Integration\Fixtures;

class IntegrationTestInterceptor
{
    public function beforeCreateOrUpdate(array $data): array
    {
        $data['updated'] = 'yes';

        return $data;
    }

    public function afterCreatedOrUpdated(object $entity, array $data): void
    {
        if ($entity instanceof IntegrationTestModel) {
            $entity->intercepted = true;
        }
    }
}
