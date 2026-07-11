<?php

declare(strict_types=1);

namespace Capell\Installer\Filament\Pages;

use BackedEnum;
use Capell\Installer\Support\InstallerSessionRepository;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Override;

class InstallProgressPage extends Page
{
    public string $installId;

    public string $installStatus = 'running';

    protected static BackedEnum|string|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?string $slug = 'install-progress/{installId}';

    protected string $view = 'capell-installer::filament.pages.install-progress-page';

    #[Override]
    public static function canAccess(): bool
    {
        return auth()->user() !== null;
    }

    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    #[Override]
    public function getTitle(): string|Htmlable
    {
        return __('capell-installer::installer.progress_heading');
    }

    public function mount(string $installId): void
    {
        $this->installId = $installId;
        $this->installStatus = $this->sessions()->status($installId, 'running');
    }

    /** @return list<string> */
    public function lines(): array
    {
        return $this->sessions()->outputMessages($this->installId);
    }

    public function progressDataUrl(): string
    {
        return route('capell-installer.progress.data', ['installId' => $this->installId]);
    }

    public function reportUrl(): string
    {
        return route('capell-installer.progress.download', ['installId' => $this->installId]);
    }

    public function reportDownloadFilename(): string
    {
        return sprintf('capell-install-%s.json', $this->installId);
    }

    private function sessions(): InstallerSessionRepository
    {
        return resolve(InstallerSessionRepository::class);
    }
}
