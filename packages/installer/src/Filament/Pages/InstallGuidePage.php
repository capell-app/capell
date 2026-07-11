<?php

declare(strict_types=1);

namespace Capell\Installer\Filament\Pages;

use BackedEnum;
use Capell\Installer\Actions\InstallGuide\ApplyInstallGuidePatchesAction;
use Capell\Installer\Data\InstallGuide\ApplyPatchesInputData;
use Capell\Installer\Support\InstallerInstallationState;
use Capell\Installer\Support\InstallGuide\PatchRegistry;
use Capell\Installer\Support\InstallGuide\PatchResult;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Override;
use Throwable;

class InstallGuidePage extends Page
{
    private const string INSTALL_PERMISSIONS_DOC_URL = 'https://docs.capell.app/getting-started/install/#install-time-write-permissions';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string> */
    public array $selectedPatches = [];

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

        $superAdminRole = config('capell.roles.super_admin', 'super_admin');

        return method_exists($user, 'hasRole') && $user->hasRole($superAdminRole);
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
            ->map(static fn ($group) => $group->map(static fn ($patch): array => [
                'id' => $patch->id(),
                'label' => $patch->label(),
                'description' => $patch->description(),
                'docUrl' => $patch->docUrl(),
                'status' => $patch->probe(),
                'reason' => $patch->reason(),
                'defaultEnabled' => $patch->defaultEnabled(),
            ])->all())->all();

        $this->data['patches'] = $grouped;
        $this->selectedPatches = [];
        $this->data['selectedPatches'] = [];

        // Pre-select applicable patches
        foreach ($allPatches as $patch) {
            if ($patch->defaultEnabled() && $patch->probe()->value === 'applicable') {
                $this->selectedPatches[] = $patch->id();
                $this->data['selectedPatches'][] = $patch->id();
            }
        }
    }

    public function applyPatches(): void
    {
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
            $inputData = new ApplyPatchesInputData(patchIds: $selectedPatchIds);
            $result = ApplyInstallGuidePatchesAction::run($inputData);

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
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title(__('capell-installer::install-guide.apply_failed'))
                ->body($throwable->getMessage())
                ->send();
        }
    }

    /** @return array<int, Action> */
    #[Override]
    protected function getActions(): array
    {
        return [
            Action::make('apply')
                ->label(__('capell-installer::install-guide.apply_changes'))
                ->action('applyPatches')
                ->requiresConfirmation()
                ->modalHeading(__('capell-installer::install-guide.confirm_apply'))
                ->modalDescription(__('capell-installer::install-guide.confirm_apply_description')),
        ];
    }
}
