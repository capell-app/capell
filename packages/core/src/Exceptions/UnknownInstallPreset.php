<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use InvalidArgumentException;

final class UnknownInstallPreset extends InvalidArgumentException
{
    /** @param list<string> $validKeys */
    public function __construct(public readonly string $key, public readonly array $validKeys)
    {
        parent::__construct((string) __('capell-core::install.preset.unknown', ['preset' => $key]));
    }
}
