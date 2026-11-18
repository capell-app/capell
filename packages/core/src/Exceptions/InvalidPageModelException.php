<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use Exception;

class InvalidPageModelException extends Exception
{
    public static function forModel(string $model): self
    {
        return new self(sprintf('Invalid page class: %s', $model));
    }
}
