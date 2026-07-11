<?php

declare(strict_types=1);

namespace Capell\Core\Tests;

use Capell\Core\Facades\CapellCore;
use Capell\Tests\AbstractTestCase;
use Livewire\LivewireServiceProvider;

class CoreTestCase extends AbstractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerAndMigrateSettings(
            CapellCore::getSettingMigrations(),
            __DIR__ . '/../../../packages/core/database/settings',
        );
    }

    protected function getPackageServiceName(): string
    {
        return 'capell-core';
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return array_values([
            ...parent::getDefaultPackageProviders(),
            LivewireServiceProvider::class,
        ]);
    }
}
