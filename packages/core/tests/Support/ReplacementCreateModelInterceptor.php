<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Override;

final class ReplacementCreateModelInterceptor implements CreateModelInterceptorContract
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[Override]
    public function beforeCreate(array $data): array
    {
        $data['steps'][] = 'replacement';

        return $data;
    }

    #[Override]
    public function afterCreated(object $entity, array $data): void {}
}
