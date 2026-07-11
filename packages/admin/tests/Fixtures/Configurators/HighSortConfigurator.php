<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Configurators;

use Capell\Admin\Contracts\ConfiguratorInterface;
use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Filament\Schemas\Schema;

class HighSortConfigurator implements ConfiguratorInterface
{
    public static function getKey(): string
    {
        return 'High';
    }

    public static function getSort(): int
    {
        return 20;
    }

    public static function configure(Schema $schema, ?ConfiguratorContextData $context = null): Schema
    {
        return $schema;
    }
}
