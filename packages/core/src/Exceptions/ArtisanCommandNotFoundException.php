<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;

/** No installed package registers the Artisan command, in the running application or a fresh process. */
final class ArtisanCommandNotFoundException extends RuntimeException
{
    public function __construct(public readonly string $command)
    {
        parent::__construct(__('capell-core::install.command.not_found', ['command' => $command]));
    }
}
