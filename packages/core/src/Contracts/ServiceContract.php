<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

interface ServiceContract
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(array $input): mixed;

    public function handles(): string;
}
