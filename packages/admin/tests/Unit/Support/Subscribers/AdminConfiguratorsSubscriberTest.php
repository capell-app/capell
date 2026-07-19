<?php

declare(strict_types=1);

use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Support\Subscribers\AdminConfiguratorsSubscriber;
use Capell\Core\Enums\ListenerEnum;

it('clears the configurator cache only when a package is uninstalled', function (): void {
    CapellAdmin::shouldReceive('clearCachedConfigurators')->once();

    $subscriber = resolve(AdminConfiguratorsSubscriber::class);
    $context = new stdClass;

    $subscriber->handle(ListenerEnum::PackageInstalled->value, $context);
    $subscriber->handle(ListenerEnum::PackageUninstalled->value, $context);
});
