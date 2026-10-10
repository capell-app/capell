<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

interface CreateModelInterceptorContract
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function beforeCreate(array $data): array;

    /** @param array<string, mixed> $data */
    public function afterCreated(object $entity, array $data): void;
}
