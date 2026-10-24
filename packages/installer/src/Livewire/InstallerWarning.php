<?php

declare(strict_types=1);

namespace Capell\Installer\Livewire;

use Capell\Core\Support\Permissions\SiteAccess;
use Capell\Installer\Actions\GetActiveInstallAction;
use Capell\Installer\Actions\RemoveSetupPackageAction;
use Capell\Installer\Filament\Pages\InstallGuidePage;
use Capell\Installer\Support\InstallerInstallationState;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

#[Lazy]
final class InstallerWarning extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    public bool $capellIsInstalled = false;

    public bool $installerPackageIsInstalled = false;

    public function mount(): void
    {
        $this->capellIsInstalled = InstallerInstallationState::capellIsInstalled();
        $this->installerPackageIsInstalled = InstallerInstallationState::installerPackageIsInstalled();
    }

    public function deleteInstallerAction(): Action
    {
        return Action::make('deleteInstaller')
            ->authorize(fn (): bool => $this->canDeleteInstaller())
            ->label(__('capell-installer::widgets.delete_installer_action'))
            ->color('danger')
            ->outlined()
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading(__('capell-installer::widgets.delete_installer_modal_heading'))
            ->modalDescription(__('capell-installer::widgets.delete_installer_confirm'))
            ->modalContent(view('capell-installer::components.delete-installer-modal', [
                'installUrl' => route('capell-installer.show'),
            ]))
            ->action(function (): RedirectResponse {
                abort_unless($this->canDeleteInstaller(), 403);

                return redirect()->to(RemoveSetupPackageAction::run(requiresServerSideTooling: true));
            });
    }

    public function render(): View
    {
        try {
            $installGuideUrl = InstallGuidePage::getUrl();
        } catch (RouteNotFoundException) {
            $installGuideUrl = route('capell-installer.show');
        }

        return view('capell-installer::components.installer-warning', [
            'activeInstall' => GetActiveInstallAction::run(),
            'capellIsInstalled' => $this->capellIsInstalled,
            'installGuideUrl' => $installGuideUrl,
            'installUrl' => route('capell-installer.show'),
            'installerPackageIsInstalled' => $this->installerPackageIsInstalled,
            'shouldShowWarning' => $this->installerPackageIsInstalled,
        ]);
    }

    private function canDeleteInstaller(): bool
    {
        return SiteAccess::current()->isGlobal()
            && config('capell.server_side_tooling', false) === true;
    }
}
