<div>
    @php
        use Capell\Installer\Filament\Pages\InstallGuidePage;
        use Capell\Installer\Support\InstallerInstallationState;
        use Illuminate\Support\Facades\Route;

        $capellIsInstalled ??= InstallerInstallationState::capellIsInstalled();
        $installerPackageIsInstalled ??= InstallerInstallationState::installerPackageIsInstalled();
        $activeInstall ??= null;
        $installUrl ??= route('capell-installer.show');
        $installGuideUrl ??= Route::has('filament.admin.pages.install-guide') ? InstallGuidePage::getUrl() : $installUrl;
        $shouldShowWarning ??= $installerPackageIsInstalled;
    @endphp

    @if ($shouldShowWarning)
        <x-filament::callout
            :color="$capellIsInstalled ? 'danger' : 'warning'"
            :icon="$capellIsInstalled ? 'heroicon-o-shield-exclamation' : 'heroicon-o-wrench-screwdriver'"
            :heading="
                $capellIsInstalled
                ? __('capell-installer::widgets.installer_installed_heading')
                : __('capell-installer::widgets.not_installed_heading')
            "
            :description="
                $capellIsInstalled
                ? __('capell-installer::widgets.installer_installed_message')
                : __('capell-installer::widgets.not_installed_message')
            "
        >
            <x-slot name="footer">
                @if ($capellIsInstalled)
                    <x-filament::link
                        :href="$installUrl"
                        tag="a"
                    >
                        {{ __('capell-installer::widgets.reinstall_action') }}
                    </x-filament::link>
                @else
                    <x-filament::link
                        :href="$installUrl"
                        tag="a"
                    >
                        {{ __('capell-installer::widgets.install_action') }}
                    </x-filament::link>
                @endif
            </x-slot>

            <x-slot name="controls">
                <div class="flex flex-wrap items-center gap-2">
                    @if ($capellIsInstalled)
                        {{ $this->deleteInstallerAction }}
                    @else
                        <x-filament::button
                            :href="$installGuideUrl"
                            tag="a"
                            color="gray"
                            size="sm"
                            :outlined="true"
                        >
                            {{ __('capell-installer::widgets.install_guide_action') }}
                        </x-filament::button>
                    @endif

                    @if ($activeInstall ?? null)
                        <x-filament::button
                            :href="$activeInstall->progressUrl"
                            tag="a"
                            color="info"
                            size="sm"
                            :outlined="true"
                        >
                            {{ __('capell-installer::widgets.active_install_action') }}
                        </x-filament::button>
                    @endif
                </div>
            </x-slot>
        </x-filament::callout>

        @if ($activeInstall ?? null)
            <div
                class="border-info-200 bg-info-50 text-info-900 dark:border-info-800 dark:bg-info-950 dark:text-info-100 mt-3 rounded-lg border px-4 py-3 text-sm"
            >
                <div class="font-medium">
                    {{ __('capell-installer::widgets.active_install_heading') }}
                </div>

                <div class="mt-1">
                    {{
                        __('capell-installer::widgets.active_install_details', [
                            'installId' => $activeInstall->shortInstallId(),
                            'status' => __('capell-installer::installer.status_' . $activeInstall->status),
                            'steps' => $activeInstall->planStepCount,
                        ])
                    }}
                </div>

                <div class="mt-2 flex flex-wrap gap-3">
                    <x-filament::link
                        :href="$activeInstall->progressUrl"
                        tag="a"
                    >
                        {{ __('capell-installer::widgets.active_install_progress_link') }}
                    </x-filament::link>

                    <x-filament::link
                        :href="$activeInstall->reportUrl"
                        tag="a"
                        target="_blank"
                    >
                        {{ __('capell-installer::widgets.active_install_report_link') }}
                    </x-filament::link>
                </div>
            </div>
        @endif

        @if ($capellIsInstalled)
            <x-filament-actions::modals />
        @endif
    @endif
</div>
