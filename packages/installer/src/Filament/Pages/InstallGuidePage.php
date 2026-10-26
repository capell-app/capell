<?php

declare(strict_types=1);

namespace Capell\Installer\Filament\Pages;

use BackedEnum;
use Capell\Core\Data\Patching\PatchFilePreviewData;
use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Core\Support\Permissions\SiteAccess;
use Capell\Installer\Actions\InstallGuide\ApplyReviewedInstallGuidePatchesAction;
use Capell\Installer\Actions\InstallGuide\ReviewInstallGuidePatchesAction;
use Capell\Installer\Data\InstallGuide\ReviewedPatchData;
use Capell\Installer\Support\InstallerInstallationState;
use Capell\Installer\Support\InstallGuide\PatchRegistry;
use Capell\Installer\Support\InstallGuide\PatchResult;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;
use Override;
use Throwable;

class InstallGuidePage extends Page
{
    private const string INSTALL_PERMISSIONS_DOC_URL = 'https://docs.capell.app/getting-started/install/#install-time-write-permissions';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string> */
    public array $selectedPatches = [];

    #[Locked]
    public ?string $reviewToken = null;

    /** @var array<int, array<string, mixed>> */
    #[Locked]
    public array $review = [];

    /** @var array<int, array<string, mixed>> */
    #[Locked]
    public array $patchResults = [];

    protected static BackedEnum|string|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $slug = 'install-guide';

    protected string $view = 'capell-installer::filament.pages.install-guide-page';

    #[Override]
    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        config('capell.roles.super_admin', 'super_admin');

        return method_exists($user, 'hasRole') && SiteAccess::forActor($user)->isGlobal();
    }

    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess() && InstallerInstallationState::capellIsNotInstalled();
    }

    #[Override]
    public function getTitle(): string|Htmlable
    {
        return __('capell-installer::navigation.install_guide');
    }

    public function mount(): void
    {
        $patchRegistry = resolve(PatchRegistry::class);
        $allPatches = $patchRegistry->all();

        $grouped = $allPatches
            ->groupBy(static fn ($patch): string => $patch->group())
            ->map(static fn ($group) => $group->map(static function (Patch $patch): array {
                try {
                    $status = $patch->probe();
                    $reason = $patch->reason();
                } catch (Throwable) {
                    // A failed refresh must not hide completed writes or recovery paths.
                    $status = PatchStatus::Unsupported;
                    $reason = null;
                }

                return [
                    'id' => $patch->id(),
                    'label' => $patch->label(),
                    'description' => $patch->description(),
                    'docUrl' => $patch->docUrl(),
                    'status' => $status,
                    'reason' => $reason,
                    'defaultEnabled' => $patch->defaultEnabled(),
                ];
            })->all())->all();

        $this->data['patches'] = $grouped;
        $this->selectedPatches = [];
        $this->data['selectedPatches'] = [];

        // Pre-select applicable patches
        foreach ($grouped as $patches) {
            foreach ($patches as $patch) {
                if ($patch['defaultEnabled'] && $patch['status'] === PatchStatus::Applicable) {
                    $this->selectedPatches[] = $patch['id'];
                    $this->data['selectedPatches'][] = $patch['id'];
                }
            }
        }
    }

    public function reviewPatches(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->validate(['data.selectedPatches' => ['required', 'array', 'min:1'], 'data.selectedPatches.*' => ['string']]);
        $result = ReviewInstallGuidePatchesAction::run($this->data['selectedPatches']);
        $this->reviewToken = $result->token;
        $this->review = $result->patches->map(static fn (ReviewedPatchData $patch): array => [
            'id' => $patch->patchId,
            'label' => $patch->label,
            'status' => $patch->status->getLabel(),
            'guidance' => $patch->guidance,
            'files' => array_map(static fn (PatchFilePreviewData $file): array => $file->toArray(), $patch->preview->files ?? []),
        ])->all();
        $this->patchResults = [];
    }

    public function updatedData(mixed $value, ?string $key = null): void
    {
        if ($key === 'selectedPatches' || str_starts_with($key ?? '', 'selectedPatches.')) {
            $this->reviewToken = null;
            $this->review = [];
            session()->forget('capell.install-guide.review');
        }
    }

    public function applyPatches(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->validate(['data.selectedPatches' => ['array'], 'data.selectedPatches.*' => ['string']]);
        $selectedPatchIds = $this->data['selectedPatches'] ?? [];

        if ($selectedPatchIds === []) {
            Notification::make()
                ->warning()
                ->title(__('capell-installer::install-guide.no_patches_selected'))
                ->body(__('capell-installer::install-guide.select_patches_to_apply'))
                ->send();

            return;
        }

        try {
            $result = ApplyReviewedInstallGuidePatchesAction::run($selectedPatchIds, $this->reviewToken ?? '');
            $this->patchResults = $result->results->map(static fn (PatchResult $patch): array => [
                'id' => $patch->patchId,
                'label' => $patch->label,
                'status' => $patch->statusAfter->getLabel(),
                'failed' => $patch->failed(),
                'manualRequired' => $patch->manualRequired,
                'recoveryPaths' => $patch->recoveryPaths,
                'createdPaths' => $patch->createdPaths,
            ])->all();

            foreach ($result->results as $patchResult) {
                if ($patchResult->failed()) {
                    Notification::make()
                        ->danger()
                        ->title($patchResult->label)
                        ->body($patchResult->errorMessage ?? __('capell-installer::install-guide.patch_failed'))
                        ->send();
                } elseif ($patchResult->succeeded()) {
                    Notification::make()
                        ->success()
                        ->title($patchResult->label)
                        ->body(__('capell-installer::install-guide.patch_applied_success'))
                        ->send();
                } else {
                    Notification::make()
                        ->info()
                        ->title($patchResult->label)
                        ->body(__('capell-installer::install-guide.patch_skipped'))
                        ->send();
                }
            }

            if ($result->failed()->isNotEmpty()) {
                Notification::make()
                    ->warning()
                    ->title(__('capell-installer::install-guide.manual_changes_required'))
                    ->body(__('capell-installer::install-guide.manual_changes_required_body', [
                        'patches' => $result->failed()
                            ->map(fn (PatchResult $patchResult): string => $patchResult->label)
                            ->implode(', '),
                        'url' => self::INSTALL_PERMISSIONS_DOC_URL,
                    ]))
                    ->send();
            }

            $this->mount();
        } catch (Throwable) {
            Notification::make()
                ->danger()
                ->title(__('capell-installer::install-guide.apply_failed'))
                ->body(__('capell-installer::install-guide.review_required'))
                ->send();
        } finally {
            $this->reviewToken = null;
            $this->review = [];
        }
    }

    /** @return array<int, Action> */
    #[Override]
    protected function getActions(): array
    {
        return [
            Action::make('review')
                ->label(__('capell-installer::install-guide.review_changes'))
                ->action('reviewPatches'),
            Action::make('apply')
                ->label(__('capell-installer::install-guide.apply_changes'))
                ->action('applyPatches')
                ->disabled(fn (): bool => $this->reviewToken === null)
                ->requiresConfirmation()
                ->modalHeading(__('capell-installer::install-guide.confirm_apply'))
                ->modalDescription(__('capell-installer::install-guide.confirm_apply_description')),
        ];
    }
}
