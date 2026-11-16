<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Extensions;

use Capell\Admin\Support\AdminRuntimeActivator;
use Capell\Core\Contracts\Extensions\PreparesExtensionContributionReceipts;
use Illuminate\Contracts\Container\Container;
use Override;

/**
 * Prepares the admin runtime so admin bridge receipts exist before an audit.
 */
final readonly class AdminContributionReceiptPreparer implements PreparesExtensionContributionReceipts
{
    public function __construct(private Container $container) {}

    #[Override]
    public function prepareContributionReceipts(): void
    {
        if (! $this->container->bound(AdminRuntimeActivator::class)) {
            return;
        }

        $this->container->make(AdminRuntimeActivator::class)->prepare();
    }
}
