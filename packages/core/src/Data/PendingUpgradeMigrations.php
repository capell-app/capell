<?php

declare(strict_types=1);

namespace Capell\Core\Data;

final readonly class PendingUpgradeMigrations
{
    /**
     * @param  array<string, string>  $core
     * @param  array<string, string>  $published
     */
    public function __construct(
        public array $core,
        public array $published,
    ) {}
}
