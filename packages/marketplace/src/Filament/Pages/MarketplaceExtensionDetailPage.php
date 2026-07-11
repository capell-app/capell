<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Core\Data\Marketplace\ExtensionLicenceDecisionData;
use Capell\Core\Enums\ExtensionLicenceStatus;
use Capell\Marketplace\Actions\SubmitExtensionFeedbackAction;
use Capell\Marketplace\Data\ExtensionDetailData;
use Capell\Marketplace\Data\ExtensionFeedbackData;
use Capell\Marketplace\Enums\MarketplacePermission;
use Capell\Marketplace\Filament\Widgets\ExtensionHealthAlertsFilamentWidget;
use Capell\Marketplace\Services\MarketplaceClient;
use Capell\Marketplace\Support\MarketplaceWebUrl;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
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

    public ?string $detailLoadError = null;

    public bool $showManualInstallCommands = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static ?string $slug = 'extensions/marketplace/{slug}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'capell-marketplace::filament.pages.marketplace-extension-detail';

    private ?ExtensionDetailData $resolvedDetail = null;

    #[Override]
    public static function canAccess(): bool
    {
        if (ExtensionsPage::canAccess()) {
            return true;
        }

        return auth()->user()?->can(MarketplacePermission::ViewMarketplacePage->value) ?? false;
    }

    public function mount(string $slug): void
    {
        $this->extensionSlug = $slug;

        try {
            $detail = $this->detail();
        } catch (InvalidArgumentException) {
            throw new NotFoundHttpException;
        } catch (RuntimeException $runtimeException) {
            $this->detailLoadError = $runtimeException->getMessage();

            Notification::make()
                ->title(__('capell-marketplace::marketplace.detail.unavailable_heading'))
                ->body($this->detailLoadError)
                ->danger()
                ->send();

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
            Notification::make()
                ->title(__('capell-marketplace::marketplace.feedback.failed'))
                ->body($runtimeException->getMessage())
                ->danger()
                ->send();

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
        $priceCents = $this->detail()->priceCents ?? 0;

        if ($priceCents <= 0) {
            return (string) __('capell-marketplace::marketplace.install.free');
        }

        return '$' . number_format($priceCents / 100, 2);
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
