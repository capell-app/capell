<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Core\Data\Marketplace\ExtensionLicenceDecisionData;
use Capell\Core\Enums\ExtensionLicenceStatus;
use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Actions\AssertNoActiveMarketplaceOperationAction;
use Capell\Marketplace\Actions\BuildMarketplaceSuitePresentationAction;
use Capell\Marketplace\Actions\EvaluateMarketplaceEnvironmentReadinessAction;
use Capell\Marketplace\Actions\InstallMarketplaceExtensionAction;
use Capell\Marketplace\Actions\SubmitExtensionFeedbackAction;
use Capell\Marketplace\Actions\UpdateMarketplaceExtensionAction;
use Capell\Marketplace\Data\ExtensionDetailData;
use Capell\Marketplace\Data\ExtensionFeedbackData;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceEnvironmentReadinessData;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Data\MarketplaceInstallRequestData;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Enums\MarketplaceInstallState;
use Capell\Marketplace\Enums\MarketplacePermission;
use Capell\Marketplace\Filament\Support\MarketplaceErrorPresenter;
use Capell\Marketplace\Filament\Support\MarketplaceInstallActionPresenter;
use Capell\Marketplace\Filament\Support\MarketplaceUpdateChangelogPresenter;
use Capell\Marketplace\Filament\Widgets\ExtensionHealthAlertsFilamentWidget;
use Capell\Marketplace\Services\MarketplaceClient;
use Capell\Marketplace\Support\MarketplaceWebUrl;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Override;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MarketplaceExtensionDetailPage extends Page
{
    use HasPageShield;

    public string $extensionSlug = '';

    public ?int $feedbackRating = null;

    public ?string $feedbackComment = null;

    public ?string $feedbackTip = null;

    public ?string $feedbackStatus = null;

    public ?string $licenseKey = null;

    public ?string $detailLoadError = null;

    public bool $showManualInstallCommands = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static ?string $slug = 'extensions/marketplace/{slug}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'capell-marketplace::filament.pages.marketplace-extension-detail';

    private ?ExtensionDetailData $resolvedDetail = null;

    private ?MarketplaceEnvironmentReadinessData $environmentReadiness = null;

    #[Override]
    public static function canAccess(): bool
    {
        if (ExtensionsPage::canAccess()) {
            return true;
        }

        return auth()->user()?->can(MarketplacePermission::ViewMarketplacePage->value) ?? false;
    }

    /**
     * Without this Filament humanises the class name, so anywhere the label is
     * read it says "Marketplace Extension Detail Page".
     */
    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-marketplace::marketplace.detail.title');
    }

    public function mount(string $slug): void
    {
        $this->extensionSlug = $slug;

        try {
            $detail = $this->detail();
        } catch (InvalidArgumentException) {
            throw new NotFoundHttpException;
        } catch (RuntimeException $runtimeException) {
            $this->detailLoadError = (string) __('capell-marketplace::marketplace.errors.operator_action_failed');

            MarketplaceErrorPresenter::notification(
                (string) __('capell-marketplace::marketplace.detail.unavailable_heading'),
                $runtimeException,
                ['extension_slug' => $this->extensionSlug],
            )->send();

            return;
        }

        throw_unless($detail instanceof ExtensionDetailData, NotFoundHttpException::class);
    }

    #[Override]
    public function getTitle(): string
    {
        if ($this->detailLoadError !== null) {
            return (string) __('capell-marketplace::marketplace.detail.title');
        }

        return $this->detail()->name ?? (string) __('capell-marketplace::marketplace.detail.title');
    }

    public function detail(): ?ExtensionDetailData
    {
        if ($this->resolvedDetail instanceof ExtensionDetailData) {
            return $this->resolvedDetail;
        }

        if ($this->detailLoadError !== null) {
            return null;
        }

        if ($this->extensionSlug === '') {
            return null;
        }

        return $this->resolvedDetail = resolve(MarketplaceClient::class)->getExtensionDetail($this->extensionSlug);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function publicDocumentation(): array
    {
        return array_values(array_filter(
            $this->detail()->documentation ?? [],
            fn (array $document): bool => ! (bool) ($document['private'] ?? false) || $this->canViewPrivateDocs(),
        ));
    }

    public function canViewPrivateDocs(): bool
    {
        return (bool) $this->detail()?->licence?->canViewPrivateDocs;
    }

    public function canDownload(): bool
    {
        return (bool) $this->detail()?->licence?->canDownload;
    }

    public function canInstall(): bool
    {
        return (bool) $this->detail()?->licence?->canInstall;
    }

    public function requiresLicenceKey(): bool
    {
        $detail = $this->detail();

        if (! $detail instanceof ExtensionDetailData) {
            return false;
        }

        return $detail->installEligibilityPolicy?->state === MarketplaceInstallState::ActivationRequired
            || $detail->installEligibility === MarketplaceInstallState::ActivationRequired->value;
    }

    public function activateLicence(): void
    {
        abort_unless(self::canAccess(), 403);

        $validated = Validator::make([
            'licenseKey' => $this->licenseKey,
        ], [
            'licenseKey' => ['required', 'string', 'max:512'],
        ], [], [
            'licenseKey' => (string) __('capell-marketplace::marketplace.install.license_key_label'),
        ])->validate();
        $detail = $this->detail();

        if (! $detail instanceof ExtensionDetailData) {
            return;
        }

        $user = auth()->user();

        try {
            InstallMarketplaceExtensionAction::run(MarketplaceInstallRequestData::make(
                extensionSlug: $detail->slug,
                options: [
                    'composer_name' => $detail->composerName,
                    'install_eligibility_policy' => $detail->installEligibilityPolicy?->toArray(),
                    'license_key' => $validated['licenseKey'],
                    '_validation_errors' => true,
                ],
                actor: $user instanceof Authenticatable
                    ? MarketplaceInstallActorData::fromAuthenticatable($user)
                    : MarketplaceInstallActorData::system('marketplace-extension-detail'),
                betaAcknowledged: false,
                source: MarketplaceInstallSource::LocalUi,
            ));
        } catch (ValidationException $validationException) {
            $message = collect($validationException->errors())->flatten()->first();
            $this->addError('licenseKey', is_string($message)
                ? $message
                : (string) __('capell-marketplace::marketplace.install.license_key_invalid'));

            return;
        }

        $this->reset('licenseKey');
    }

    /**
     * The version this site is running, or null when the extension is not
     * installed here at all.
     *
     * Resolved through the package registry first, the way the catalogue
     * provider does, and only then through Composer's own metadata. Asking
     * Composer alone answers null for a package the registry knows about, which
     * would leave this page believing an installed extension is not installed —
     * a third answer to a question the card and the table already agree on.
     */
    public function installedVersion(): ?string
    {
        $composerName = $this->detail()?->composerName;

        if (! is_string($composerName) || $composerName === '') {
            return null;
        }

        foreach (ExtensionListingData::localPackageComposerNameCandidates($composerName) as $candidateComposerName) {
            if (CapellCore::hasPackage($candidateComposerName)) {
                return CapellCore::getPackage($candidateComposerName)->version
                    ?? CapellCore::getInstalledPrettyVersion($candidateComposerName);
            }
        }

        return null;
    }

    /**
     * Whether the Update button belongs on this page.
     *
     * Delegates to the presenter for the same reason the card does: a bare
     * version comparison here would offer an update for records the presenter
     * has already ruled out as blocked, incompatible or mid-operation, and an
     * offer the product then refuses downstream is worse than no offer at all.
     */
    public function canUpdate(): bool
    {
        return resolve(MarketplaceInstallActionPresenter::class)
            ->canUpdate($this->updateEligibilityRecord());
    }

    /**
     * What the operator is consenting to, shown in the confirmation rather than
     * only a version number. Consent to an unseen change is not consent.
     *
     * @return list<array{version: string, kind: string, notes: string}>
     */
    public function updateChangelog(): array
    {
        $detail = $this->detail();

        if (! $detail instanceof ExtensionDetailData) {
            return [];
        }

        return resolve(MarketplaceUpdateChangelogPresenter::class)
            ->entriesSince($detail, $this->installedVersion());
    }

    public function updateExtension(): void
    {
        // Page-level canAccess() gates the render; this gates the call. A
        // Livewire method is reachable on its own, so the mutating entry point
        // authorizes itself the way the card's does rather than trusting that
        // whoever reached it came through a page they were allowed to see.
        abort_unless(self::canAccess(), 403);

        $composerName = $this->detail()?->composerName;

        if (! is_string($composerName) || $composerName === '') {
            return;
        }

        $user = auth()->user();

        try {
            $attempt = UpdateMarketplaceExtensionAction::run(
                composerName: $composerName,
                actor: $user instanceof Authenticatable
                    ? MarketplaceInstallActorData::fromAuthenticatable($user)
                    : MarketplaceInstallActorData::system('marketplace-extension-detail'),
            );
        } catch (ValidationException $validationException) {
            Notification::make()
                ->warning()
                ->title((string) __('capell-marketplace::marketplace.selection.unavailable_title'))
                ->body(collect($validationException->errors())->flatten()->first()
                    ?? (string) __('capell-marketplace::marketplace.selection.unavailable_body'))
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title((string) __('capell-marketplace::marketplace.updates.queued_title'))
            ->body((string) __('capell-marketplace::marketplace.updates.queued_body', [
                'name' => $attempt->extension_name,
            ]))
            ->send();
    }

    public function installDecisionLabel(): string
    {
        return $this->canInstall()
            ? (string) __('capell-marketplace::marketplace.detail.install_decision_yes')
            : (string) __('capell-marketplace::marketplace.detail.install_decision_no');
    }

    public function installDecisionReason(): string
    {
        return $this->detail()->blockedReason
            ?? (string) __('capell-marketplace::marketplace.detail.access_body');
    }

    public function nextActionLabel(): string
    {
        return $this->detail()->nextAction
            ?? ($this->canInstall()
                ? (string) __('capell-marketplace::marketplace.detail.install_available')
                : (string) __('capell-marketplace::marketplace.detail.verify_site_cta'));
    }

    public function contributionCount(): int
    {
        return array_sum($this->detail()->contributionSummary ?? []);
    }

    public function frontendRenderBudgetLabel(): ?string
    {
        $budget = $this->detail()?->performanceBudget['frontendRenderBudgetMs'] ?? null;

        return is_numeric($budget)
            ? (string) __('capell-marketplace::marketplace.detail.frontend_budget_ms', ['ms' => (int) $budget])
            : null;
    }

    public function canSubmitFeedback(): bool
    {
        if ($this->canComment()) {
            return true;
        }

        return $this->canRate();
    }

    public function canRate(): bool
    {
        $licence = $this->detail()?->licence;

        return $licence instanceof ExtensionLicenceDecisionData && $licence->canRate;
    }

    public function canComment(): bool
    {
        $licence = $this->detail()?->licence;

        return $licence instanceof ExtensionLicenceDecisionData && $licence->canComment;
    }

    public function submitFeedback(): void
    {
        $feedbackComment = $this->canComment() ? $this->blankStringToNull($this->feedbackComment) : null;
        $feedbackTip = $this->canComment() ? $this->blankStringToNull($this->feedbackTip) : null;

        Validator::make([
            'feedbackRating' => $this->feedbackRating,
            'feedbackComment' => $feedbackComment,
            'feedbackTip' => $feedbackTip,
        ], [
            'feedbackRating' => $this->feedbackRatingRules(),
            'feedbackComment' => $this->feedbackCommentRules(),
            'feedbackTip' => [$this->canComment() ? 'nullable' : 'prohibited', 'string', 'max:2000'],
        ])->validate();

        try {
            $result = SubmitExtensionFeedbackAction::run(new ExtensionFeedbackData(
                slug: $this->extensionSlug,
                rating: $this->canRate() ? $this->feedbackRating : null,
                comment: $feedbackComment,
                tip: $feedbackTip,
            ));
        } catch (RuntimeException $runtimeException) {
            MarketplaceErrorPresenter::notification(
                (string) __('capell-marketplace::marketplace.feedback.failed'),
                $runtimeException,
                ['extension_slug' => $this->extensionSlug],
            )->send();

            return;
        }

        $this->feedbackStatus = is_scalar($result['status'] ?? null) ? (string) $result['status'] : null;

        Notification::make()
            ->title(__('capell-marketplace::marketplace.feedback.submitted'))
            ->body(is_scalar($result['message'] ?? null) ? (string) $result['message'] : null)
            ->success()
            ->send();
    }

    public function shouldVerifySite(): bool
    {
        return in_array($this->detail()?->licence?->licenceStatus, [
            ExtensionLicenceStatus::Purchased,
            ExtensionLicenceStatus::Unverified,
            ExtensionLicenceStatus::DomainMismatch,
        ], true);
    }

    public function licenceStatusLabel(): string
    {
        $status = $this->detail()?->licence?->licenceStatus ?? ExtensionLicenceStatus::None;

        return (string) __('capell-marketplace::marketplace.detail.licence_statuses.' . $status->value);
    }

    public function priceLabel(): string
    {
        $detail = $this->detail();
        $priceCents = $detail?->priceCents ?? 0;

        if ($priceCents <= 0) {
            return (string) __('capell-marketplace::marketplace.install.free');
        }

        return (string) Number::currency($priceCents / 100, $detail?->currency ?? 'USD');
    }

    /**
     * @return array{
     *   bundle: string,
     *   members: list<array{name: string, composer_name: string, price: string|null}>,
     *   combined_price: string,
     *   member_total: string|null,
     *   savings: string|null
     * }|null
     */
    public function suitePresentation(): ?array
    {
        $detail = $this->detail();

        return $detail instanceof ExtensionDetailData
            ? BuildMarketplaceSuitePresentationAction::run($detail)
            : null;
    }

    public function compatibilityLabel(): string
    {
        return Str::of($this->detail()->kind ?? '')
            ->replace('_', ' ')
            ->title()
            ->toString();
    }

    public function stateLabel(?string $state): ?string
    {
        if (! is_string($state) || $state === '') {
            return null;
        }

        return Str::of($state)
            ->replace(['-', '_'], ' ')
            ->headline()
            ->toString();
    }

    public function marketplaceUrl(): string
    {
        return MarketplaceWebUrl::resolve() . '/extensions/' . rawurlencode($this->extensionSlug);
    }

    /**
     * @return array<string, string>
     */
    public function manualInstallCommands(): array
    {
        $detail = $this->detail();

        if (! $detail instanceof ExtensionDetailData || $detail->composerName === '') {
            return [];
        }

        return [
            'composer' => $detail->manualComposerRequireCommand(),
            'install' => $detail->manualExtensionInstallCommand(),
        ];
    }

    public function environmentReadiness(): MarketplaceEnvironmentReadinessData
    {
        return $this->environmentReadiness ??= EvaluateMarketplaceEnvironmentReadinessAction::run();
    }

    /**
     * On a host that cannot install for the user, the manual commands stop being
     * a disclosure and become the primary call to action.
     */
    public function requiresManualInstallInstructions(): bool
    {
        return $this->manualInstallCommands() !== []
            && ! $this->environmentReadiness()->canInstallAutomatically();
    }

    public function showManualInstallInstructions(): void
    {
        $this->showManualInstallCommands = true;
    }

    public function ratingIsRequired(): bool
    {
        return $this->canRate() && ! $this->canComment();
    }

    /**
     * @return array<int, mixed>
     */
    public function criticalHealthAlerts(): array
    {
        $detail = $this->detail();

        if (! $detail instanceof ExtensionDetailData) {
            return [];
        }

        return ExtensionHealthAlertsFilamentWidget::criticalAlertsForExtension($detail->slug, $detail->composerName);
    }

    /**
     * The detail payload expressed in the shape every other surface hands the
     * presenter, so all three read the same keys rather than three notions of
     * "updatable".
     *
     * @return array<string, mixed>
     */
    private function updateEligibilityRecord(): array
    {
        $detail = $this->detail();

        if (! $detail instanceof ExtensionDetailData) {
            return [];
        }

        $installedVersion = $this->installedVersion();

        return [
            'composer_name' => $detail->composerName,
            'is_installed' => $installedVersion !== null,
            'has_update_available' => $this->newerVersionIsPublished($installedVersion, $detail->latestVersion),
            'install_in_progress' => $detail->composerName !== ''
                && AssertNoActiveMarketplaceOperationAction::isActive($detail->composerName),
            'is_paid' => $detail->isPaid,
            'purchase_url' => $detail->purchaseUrl,
            'install_eligibility_policy' => $detail->installEligibilityPolicy?->toArray()
                ?? $detail->installEligibility,
        ];
    }

    private function newerVersionIsPublished(?string $installedVersion, ?string $latestVersion): bool
    {
        if ($installedVersion === null || $latestVersion === null || $latestVersion === '') {
            return false;
        }

        return version_compare(ltrim($latestVersion, 'vV'), ltrim($installedVersion, 'vV'), '>');
    }

    /**
     * @return array<int, string>
     */
    private function feedbackRatingRules(): array
    {
        if (! $this->canRate()) {
            return ['prohibited'];
        }

        return [
            $this->ratingIsRequired() ? 'required' : 'nullable',
            'integer',
            'min:1',
            'max:5',
            'required_without_all:feedbackComment,feedbackTip',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function feedbackCommentRules(): array
    {
        if (! $this->canComment()) {
            return ['prohibited'];
        }

        return [
            $this->canRate() ? 'nullable' : 'required_without:feedbackTip',
            'string',
            'max:2000',
        ];
    }

    private function blankStringToNull(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
