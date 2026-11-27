<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Structure;

use InvalidArgumentException;

final class StructureKey
{
    public static function assertValid(string $key, string $subject): void
    {
        throw_unless(
            preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $key) === 1,
            InvalidArgumentException::class,
            sprintf('The %s key must use lowercase letters, numbers and single dashes (for example service-page), not [%s].', $subject, $key),
        );
    }
}
