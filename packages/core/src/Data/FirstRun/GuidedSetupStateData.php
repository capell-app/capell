<?php

declare(strict_types=1);

namespace Capell\Core\Data\FirstRun;

use Capell\Core\Models\Site;
use Spatie\LaravelData\Data;

/** Whether guided setup is still owed, and for which site. */
final class GuidedSetupStateData extends Data
{
    public function __construct(
        public readonly bool $needsSetup,
        public readonly ?int $siteId = null,
        public readonly ?string $suiteKey = null,
    ) {}

    public static function notNeeded(): self
    {
        return new self(false);
    }

    public static function forSite(Site $site): self
    {
        $suite = data_get($site->meta, 'install_suite');

        return new self(true, (int) $site->getKey(), is_string($suite) && $suite !== '' ? $suite : null);
    }
}
