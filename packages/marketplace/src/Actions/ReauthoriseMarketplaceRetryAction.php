<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Contracts\MarketplaceInstalledPackageVersionResolver;
use Capell\Marketplace\Data\ExtensionAcquisitionData;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Enums\MarketplaceOperationType;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Services\MarketplaceClient;
use Capell\Marketplace\Support\MarketplaceActivationContext;
use Capell\Marketplace\Support\MarketplaceComposerAuthContext;
use Capell\Marketplace\Support\MarketplaceInstallAuthorizationPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Ask the Marketplace again before a retry runs Composer.
 *
 * A failed attempt keeps the constraint, the eligibility and the encrypted
 * registry credentials the Marketplace gave it at the time. Days later that
 * answer may be wrong: the licence may have lapsed, been refunded or revoked,
 * and the credentials may have expired. A retry therefore never reuses them. It
 * asks through the same action the original operation used, takes the fresh
 * answer, and refuses when the Marketplace says no or hands back an
 * authorisation that has already expired.
 */
final class ReauthoriseMarketplaceRetryAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly MarketplaceClient $marketplace,
        private readonly MarketplaceInstallAuthorizationPolicy $authorizationPolicy,
    ) {}

    /**
     * Replace the retry's authorisation with a fresh one.
     *
     * @return string|null The readable reason the retry must not run, or null when it was re-authorised.
     */
    public function handle(MarketplaceInstallAttempt $retry): ?string
    {
        // An uninstall runs `composer remove` and takes no registry credentials,
        // and a delivered deployment finishes setup without Composer. Neither
        // has an authorisation to refresh.
        if ($retry->operation === MarketplaceOperationType::Uninstall
            || ($retry->context[ResumeMarketplaceAttemptsAwaitingDeploymentAction::DELIVERED_CONTEXT_KEY] ?? false) === true) {
            return null;
        }

        try {
            return $this->apply($retry);
        } catch (ValidationException $validationException) {
            return $this->denied($this->firstMessage($validationException));
        } catch (Throwable $throwable) {
            report($throwable);

            return $this->denied($throwable->getMessage());
        }
    }

    private function apply(MarketplaceInstallAttempt $retry): ?string
    {
        $unattended = MarketplaceInstallSource::tryFrom((string) ($retry->context['install_source'] ?? ''))?->isUnattended() === true;

        $acquisition = $retry->operation === MarketplaceOperationType::Update
            ? resolve(UpdateMarketplaceExtensionAction::class)->authorise($retry->composer_name, $unattended)['acquisition']
            : $this->installAcquisition($retry);

        if ($acquisition->composerName !== $retry->composer_name) {
            return $this->denied((string) __('capell-marketplace::marketplace.errors.invalid_composer_coordinates'));
        }

        if ($this->expired($acquisition->expiresAt)) {
            return (string) __('capell-marketplace::marketplace.operations.retry_reauthorisation_expired');
        }

        $eligibility = $acquisition->authorizationEligibilityPolicy;

        if ($retry->operation === MarketplaceOperationType::Install && $this->authorizationPolicy->blocksInstall($eligibility)) {
            return (string) __('capell-marketplace::marketplace.operations.retry_reauthorisation_blocked', [
                'reason' => $eligibility?->blockReason ?? $eligibility?->state?->value ?? 'blocked',
            ]);
        }

        $context = $retry->context ?? [];

        if ($retry->operation === MarketplaceOperationType::Update) {
            $context['update_from_version'] = $this->installedVersion($retry, $context);
        }

        $context = MarketplaceComposerAuthContext::encryptedInto([
            ...$context,
            'release_checksum' => $acquisition->releaseChecksum,
            'release_checksum_version' => $acquisition->releaseChecksumVersion,
            'release_content_sha256' => $acquisition->releaseContentSha256,
            'release_digests' => $acquisition->releaseDigests,
        ], $acquisition->composerAuth);

        if ($retry->operation === MarketplaceOperationType::Install) {
            $context = MarketplaceActivationContext::encryptedInto($context, $acquisition->signedActivation);
        }

        $retry->forceFill([
            'version_constraint' => $acquisition->versionConstraint,
            'composer_command' => $acquisition->composerCommand,
            'eligibility' => $eligibility?->toArray() ?? $retry->eligibility,
            'context' => $context,
        ])->save();

        RecordMarketplaceInstallAttemptEventAction::run(
            attempt: $retry,
            level: MarketplaceInstallAttemptEventLevel::Info,
            message: (string) __('capell-marketplace::marketplace.operations.retry_reauthorised'),
            stage: MarketplaceInstallFailureStage::Preflight,
        );

        return null;
    }

    private function installAcquisition(MarketplaceInstallAttempt $retry): ExtensionAcquisitionData
    {
        $listing = $this->marketplace->extensionsByComposerNames([$retry->composer_name], allowCache: false)[$retry->composer_name] ?? null;

        if (! $listing instanceof ExtensionListingData) {
            throw ValidationException::withMessages([
                'composer_name' => __('capell-marketplace::marketplace.install.not_found'),
            ]);
        }

        return CreateExtensionAcquisitionAction::run(
            listing: $listing,
            installOptions: $retry->requested_options ?? [],
        );
    }

    /** @param array<string, mixed> $context */
    private function installedVersion(MarketplaceInstallAttempt $retry, array $context): mixed
    {
        // authorise() already refused an update for a package that is not
        // installed, so the version the update starts from is today's.
        return resolve(MarketplaceInstalledPackageVersionResolver::class)->prettyVersion($retry->composer_name)
            ?? $context['update_from_version'] ?? null;
    }

    private function expired(?string $expiresAt): bool
    {
        if ($expiresAt === null || trim($expiresAt) === '') {
            return false;
        }

        try {
            return CarbonImmutable::parse($expiresAt)->isPast();
        } catch (Throwable) {
            // An expiry nobody can read is not evidence the authorisation is live.
            return true;
        }
    }

    private function denied(string $reason): string
    {
        return (string) __('capell-marketplace::marketplace.operations.retry_reauthorisation_failed', ['reason' => $reason]);
    }

    private function firstMessage(ValidationException $validationException): string
    {
        foreach ($validationException->errors() as $messages) {
            foreach ($messages as $message) {
                return (string) $message;
            }
        }

        return $validationException->getMessage();
    }
}
