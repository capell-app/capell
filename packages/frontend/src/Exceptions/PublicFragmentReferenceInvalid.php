<?php

declare(strict_types=1);

namespace Capell\Frontend\Exceptions;

use RuntimeException;

final class PublicFragmentReferenceInvalid extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Public fragment reference is invalid.');
    }
}
