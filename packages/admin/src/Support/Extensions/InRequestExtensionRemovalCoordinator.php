<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Extensions;

use Capell\Admin\Contracts\Extensions\ExtensionRemovalCoordinator;
use Capell\Admin\Data\Extensions\ExtensionRemovalOutcomeData;
use Capell\Admin\Data\Extensions\ExtensionRemovalRequestData;
use Capell\Admin\Enums\Extensions\ExtensionRemovalMode;
use Override;

/**
 * What a site with no Marketplace package can do about a removal: it in this
 * request.
 *
 * This is the historical behaviour, kept rather than removed — a Capell
 * installation is not required to have the Marketplace, and the panel's
 * uninstall button has to keep working without it. What it is not is a good
 * idea on a large extension, which is why the surfaces that use it warn the
 * operator that the removal is happening inside their request.
 *
 * The other two answers are never given here. Refusing to answer them at all
 * would be worse than answering them consistently: a caller that ignored
 * modeFor() would get an exception in production instead of the removal it
 * asked for, so queue() refuses in words rather than by throwing.
 */
final class InRequestExtensionRemovalCoordinator implements ExtensionRemovalCoordinator
{
    #[Override]
    public function modeFor(string $composerName): ExtensionRemovalMode
    {
        unset($composerName);

        return ExtensionRemovalMode::InRequest;
    }

    #[Override]
    public function manualInstructions(string $composerName, string $extensionName): string
    {
        unset($extensionName);

        return (string) __('capell-admin::generic.extension_removal_manual_instructions', [
            'package' => $composerName,
        ]);
    }

    #[Override]
    public function queue(ExtensionRemovalRequestData $request): ExtensionRemovalOutcomeData
    {
        return ExtensionRemovalOutcomeData::refused(
            title: (string) __('capell-admin::message.extension_removal_queue_unavailable'),
            body: (string) __('capell-admin::message.extension_removal_queue_unavailable_body', [
                'package' => $request->composerName,
            ]),
        );
    }
}
