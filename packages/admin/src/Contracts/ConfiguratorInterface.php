<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts;

use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Filament\Schemas\Schema;

interface ConfiguratorInterface
{
    public static function getKey(): string;

    public static function getSort(): int;

    public static function configure(Schema $schema, ?ConfiguratorContextData $context = null): Schema;
}
