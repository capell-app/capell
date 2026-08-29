<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use LogicException;

final class ExtensionContributionConflictException extends LogicException
{
    public static function duplicate(string $key, string $existingOwner, string $existingSource, string $owner, string $source): self
    {
        return new self(sprintf(
            'Extension key [%s] is already registered by [%s] (%s); [%s] (%s) cannot replace it implicitly.',
            $key,
            $existingOwner,
            $existingSource,
            $owner,
            $source,
        ));
    }

    public static function frozen(string $owner, string $source): self
    {
        return new self(sprintf(
            'Extension registry is frozen; [%s] (%s) registered too late.',
            $owner,
            $source,
        ));
    }
}
