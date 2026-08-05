<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Extensions;

use Capell\Admin\Contracts\Extensions\ExtensionRemovalCoordinator;
use Capell\Admin\Data\Extensions\ExtensionRemovalRequestData;
use Capell\Admin\Enums\Extensions\ExtensionRemovalMode;
use Filament\Notifications\Notification;

/**
 * Which of the three removal paths a panel action should take, and everything
 * the operator is told about the two it does not take itself.
 *
 * Shared by the uninstall action and the delete action because they ask the
 * same question and must not be able to answer it differently: one of them
 * quietly keeping the in-request Composer write after the other moved to the
 * queue is exactly the kind of divergence that survives review.
 */
final class ExtensionRemovalRouter
{
    /**
     * @return bool Whether the caller should perform the removal itself, inside
     *              this request. False means it has already been dealt with —
     *              handed to a worker, or turned into instructions — and the
     *              caller must do nothing further.
     */
    public static function shouldRemoveInRequest(ExtensionRemovalRequestData $request, string $extensionLabel): bool
    {
        $coordinator = resolve(ExtensionRemovalCoordinator::class);

        return match ($coordinator->modeFor($request->composerName)) {
            ExtensionRemovalMode::Queued => self::queue($coordinator, $request),
            ExtensionRemovalMode::ManualInstructions => self::discloseInstructions($coordinator, $request, $extensionLabel),
            ExtensionRemovalMode::InRequest => self::warnAboutInRequestRuntime($extensionLabel),
        };
    }

    private static function queue(ExtensionRemovalCoordinator $coordinator, ExtensionRemovalRequestData $request): bool
    {
        $outcome = $coordinator->queue($request);

        $notification = Notification::make('extension-removal-queued')
            ->title($outcome->title)
            ->body($outcome->body);

        $outcome->accepted ? $notification->success() : $notification->danger();
        $notification->send();

        return false;
    }

    /**
     * The ManualOnly call to action: the operator is not being refused, they
     * are being told where the removal happens on this host.
     */
    private static function discloseInstructions(
        ExtensionRemovalCoordinator $coordinator,
        ExtensionRemovalRequestData $request,
        string $extensionLabel,
    ): bool {
        Notification::make('extension-removal-manual')
            ->title(__('capell-admin::message.extension_removal_manual', ['extension' => $extensionLabel]))
            ->body($coordinator->manualInstructions($request->composerName, $extensionLabel))
            ->info()
            ->persistent()
            ->send();

        return false;
    }

    /**
     * Sent before the removal rather than after it, because after it there may
     * be no response left to send it in — a Composer run inside an HTTP request
     * is precisely the thing that does not finish.
     */
    private static function warnAboutInRequestRuntime(string $extensionLabel): bool
    {
        Notification::make('extension-removal-in-request')
            ->title(__('capell-admin::message.extension_removal_in_request', ['extension' => $extensionLabel]))
            ->body(__('capell-admin::message.extension_removal_in_request_body'))
            ->warning()
            ->send();

        return true;
    }
}
