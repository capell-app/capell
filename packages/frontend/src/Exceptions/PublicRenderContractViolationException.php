<?php

declare(strict_types=1);

namespace Capell\Frontend\Exceptions;

use RuntimeException;

final class PublicRenderContractViolationException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $matched,
        public readonly string $category = 'public_render_contract',
    ) {
        parent::__construct($reason . ' Matched: ' . $matched);
    }
}
