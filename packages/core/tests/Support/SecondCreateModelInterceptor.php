<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Tests\Fixtures\Models\InMemoryUserModel;
use Override;

final class SecondCreateModelInterceptor implements CreateModelInterceptorContract
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[Override]
    public function beforeCreate(array $data): array
    {
        $data['steps'][] = 'second';

        return $data;
    }

    #[Override]
    public function afterCreated(object $entity, array $data): void
    {
        if ($entity instanceof InMemoryUserModel) {
            $entity->attributes['after'][] = 'second';
        }
    }
}
