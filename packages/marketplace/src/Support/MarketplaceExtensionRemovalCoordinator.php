<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Capell\Admin\Contracts\Extensions\ExtensionRemovalCoordinator;
use Capell\Admin\Data\Extensions\ExtensionRemovalOutcomeData;
use Capell\Admin\Data\Extensions\ExtensionRemovalRequestData;
use Capell\Admin\Enums\Extensions\ExtensionRemovalMode;
use Capell\Marketplace\Actions\EvaluateMarketplaceEnvironmentReadinessAction;
use Capell\Marketplace\Actions\QueueMarketplaceUninstallAttemptAction;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Data\MarketplaceUninstallOptionsData;
use Capell\Marketplace\Enums\MarketplaceInstallCapability;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use Override;
use Throwable;

/**
 * What an installed Marketplace does about a removal the admin panel asked for.
 *
 * The dependency runs one way only: this class knows about admin, admin knows
 * nothing about it. That is not incidental tidiness — a Capell site is allowed
 * to have no Marketplace at all, and the panel's uninstall button has to keep
 * working when it does.
 */
final class MarketplaceExtensionRemovalCoordinator implements ExtensionRemovalCoordinator
{
    /**
     * Only a fully automated host gets the queue.
     *
     * AutomatedViaDeployPublisher is deliberately not included. A deploy
     * publisher automates a Composer change by writing it into the *next*
     * release, which is a coherent thing to do with an install and not with an
     * uninstall: the extension's own teardown has to run against the site that
     * currently has it, and there is no next release in which the tables it
     * owns still exist. Those hosts get the instructions instead, which is what
     * they would have had to do by hand anyway.
     */
    #[Override]
    public function modeFor(string $composerName): ExtensionRemovalMode
    {
        unset($composerName);

        return EvaluateMarketplaceEnvironmentReadinessAction::run()->capability === MarketplaceInstallCapability::Automated
            ? ExtensionRemovalMode::Queued
            : ExtensionRemovalMode::ManualInstructions;
    }

    #[Override]
    public function manualInstructions(string $composerName, string $extensionName): string
    {
        unset($extensionName);

        return (string) __('capell-marketplace::marketplace.uninstalls.manual_instructions', [
            'package' => $composerName,
        ]);
    }

    /**
     * A refusal here is an answer, not an error.
     *
     * The queue action validates the things an operator can act on — a
     * dependent extension, a theme still in use, a release root it may not
     * write — and reports them as validation messages. Turning those into an
     * exception would replace an actionable sentence with a stack trace in a
     * Livewire response.
     */
    #[Override]
    public function queue(ExtensionRemovalRequestData $request): ExtensionRemovalOutcomeData
    {
        $user = auth()->user();

        try {
            QueueMarketplaceUninstallAttemptAction::run(
                composerName: $request->composerName,
                extensionSlug: $request->extensionSlug !== '' ? $request->extensionSlug : $request->composerName,
                extensionName: $request->extensionName !== '' ? $request->extensionName : $request->composerName,
                kind: $request->kind,
                options: new MarketplaceUninstallOptionsData(
                    deletePackage: $request->deletePackage,
                    deleteData: $request->deleteData,
                    packageNames: $request->packageNames,
                    runLifecycle: $request->runLifecycle,
                ),
                actor: $user instanceof Authenticatable
                    ? MarketplaceInstallActorData::fromAuthenticatable($user)
                    : MarketplaceInstallActorData::system('capell-admin-panel'),
                source: MarketplaceInstallSource::LocalUi,
                context: ['dependent_packages' => array_slice($request->packageNames, 0, -1)],
                user: $user,
            );
        } catch (ValidationException $validationException) {
            return ExtensionRemovalOutcomeData::refused(
                title: (string) __('capell-marketplace::marketplace.uninstalls.not_queued'),
                body: implode(' ', array_merge(...array_values($validationException->errors()))),
            );
        } catch (Throwable $throwable) {
            report($throwable);

            return ExtensionRemovalOutcomeData::refused(
                title: (string) __('capell-marketplace::marketplace.uninstalls.not_queued'),
                body: $throwable->getMessage(),
            );
        }

        return ExtensionRemovalOutcomeData::accepted(
            title: (string) __('capell-marketplace::marketplace.uninstalls.queued', [
                'name' => $request->extensionName !== '' ? $request->extensionName : $request->composerName,
            ]),
            body: (string) __('capell-marketplace::marketplace.uninstalls.queued_body'),
        );
    }
}
