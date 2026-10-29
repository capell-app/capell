<?php

declare(strict_types=1);

namespace Capell\Admin\Exceptions;

use RuntimeException;
use Throwable;

final class MediaDerivativesFailedException extends RuntimeException
{
    public static function afterRollback(Throwable $previous): self
    {
        return new self(__('capell-admin::media.derivatives_failed'), 0, $previous);
    }
}
