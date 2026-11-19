<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Spatie\LaravelData\Data;

/**
 * The requirements an uninstall took off this site, for the deployment source
 * to drop too. Names only: a removal has no version or repository to carry.
 *
 * @experimental
 */
final class MarketplaceComposerRemovalRequestData extends Data
{
    /** @param  list<string>  $composerNames */
    public function __construct(
        public readonly string $operationId,
        public readonly array $composerNames,
        public readonly string $label,
    ) {}
}
