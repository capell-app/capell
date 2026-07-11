<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Filament\Configurators;

use Capell\Admin\Filament\Configurators\Sites\DefaultSiteConfigurator;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Core\Models\Site;

final class DefaultSiteConfiguratorTest extends AdminTestCase
{
    public function test_default_site_configurator_instantiates(): void
    {
        $configuratorClass = new DefaultSiteConfigurator;
        $this->assertInstanceOf(DefaultSiteConfigurator::class, $configuratorClass);
    }

    public function test_default_site_configurator_has_relation_managers_method(): void
    {
        $site = Site::factory()->createOne();

        $this->assertSame([], (new DefaultSiteConfigurator)->relationManagers($site));
    }
}
