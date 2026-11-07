<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

/** A host application's own public controller, outside every admin package. */
final class StaleCachePublicController
{
    public const string BODY = 'host page served from a stale cache';

    public function __invoke(): string
    {
        return self::BODY;
    }
}
