<?php

declare(strict_types=1);

namespace Capell\Frontend\Exceptions;

use LogicException;

final class DuplicatePublicFragmentOwner extends LogicException
{
    public function __construct(string $owner)
    {
        parent::__construct(sprintf('Public fragment owner [%s] is already registered.', $owner));
    }
}
