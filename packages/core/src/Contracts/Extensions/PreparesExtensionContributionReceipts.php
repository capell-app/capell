<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

/**
 * Prepares runtime surfaces whose contribution receipts are recorded lazily.
 *
 * Some runtimes, such as the admin panel, register their contributions only
 * when they are first prepared. The extension contract audit runs every tagged
 * preparer first, so its receipts match what a request would actually load.
 */
interface PreparesExtensionContributionReceipts
{
    public const string TAG = 'capell.extension-contribution-receipt-preparers';

    public function prepareContributionReceipts(): void;
}
