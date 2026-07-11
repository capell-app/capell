<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

interface Actionable
{
    public static function run(mixed ...$parameters): mixed;
}
