<?php

declare(strict_types=1);

namespace Capell\Tests\Support\Fakes;

use Capell\Tests\Fixtures\Models\InMemoryUserModel;

class DummyIntegrationInterceptor
{
    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function beforeCreateOrUpdate(array $data): array
    {
        $data['updated'] = true;

        return $data;
    }

    /** @param array<string, mixed> $data */
    public function afterCreatedOrUpdated(object $entity, array $data): void
    {
        if ($entity instanceof InMemoryUserModel) {
            $entity->intercepted = true;
        }
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function beforeCreate(array $data): array
    {
        $data['created'] = true;

        return $data;
    }

    /** @param array<string, mixed> $data */
    public function afterCreated(object $entity, array $data): void
    {
        if ($entity instanceof InMemoryUserModel) {
            $entity->createdIntercepted = true;
        }
    }
}
