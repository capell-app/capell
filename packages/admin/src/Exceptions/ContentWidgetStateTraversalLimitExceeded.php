<?php

declare(strict_types=1);

namespace Capell\Admin\Exceptions;

use RuntimeException;

final class ContentWidgetStateTraversalLimitExceeded extends RuntimeException
{
    public static function depth(): self
    {
        return new self('Content widget state exceeds the supported nesting depth.');
    }

    public static function nodes(): self
    {
        return new self('Content widget state exceeds the supported node count.');
    }
}
