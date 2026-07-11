<?php

declare(strict_types=1);

namespace Capell\Frontend\Exceptions;

use RuntimeException;

final class ThemeChainException extends RuntimeException
{
    public static function missingExtends(string $packageName, string $extendsTarget): self
    {
        return new self(sprintf(
            'Theme package "%s" declares extends "%s" but that package is not registered.',
            $packageName,
            $extendsTarget,
        ));
    }

    public static function cycle(string $packageName): self
    {
        return new self(sprintf(
            'Theme package "%s" creates a cyclic theme inheritance chain.',
            $packageName,
        ));
    }
}
