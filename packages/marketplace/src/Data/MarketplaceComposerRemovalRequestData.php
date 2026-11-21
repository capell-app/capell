<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use SensitiveParameter;
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
        #[SensitiveParameter]
        public readonly ?string $approvalId = null,
    ) {}

    /** @internal */
    public static function forAttempt(MarketplaceInstallAttempt $attempt): ?self
    {
        $options = MarketplaceUninstallOptionsData::fromPayload($attempt->uninstall_options);

        return $options->deletePackage
            ? new self(
                operationId: (string) $attempt->getKey(),
                composerNames: $options->packageNames !== [] ? $options->packageNames : [$attempt->composer_name],
                label: $attempt->extension_name,
            )
            : null;
    }
}
