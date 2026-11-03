<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Security;

use Capell\Core\Data\Security\SecurityAdvisoryData;

/**
 * Supplies published advisories already matched to the installed packages.
 *
 * Tag implementations with {@see self::TAG}. Core ships none; the Marketplace
 * package contributes the advisories its heartbeat snapshot stored.
 *
 * @experimental
 */
interface SecurityAdvisorySource
{
    public const string TAG = 'capell.security.advisory_source';

    public function name(): string;

    /** @return list<SecurityAdvisoryData> */
    public function advisories(): array;
}
