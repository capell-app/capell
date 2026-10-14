<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\AdminPanelIntegration;

use Capell\Admin\Data\AdminPanelIntegration\AdminPanelCandidateData;
use Capell\Core\Data\Install\PanelProviderData;
use Capell\Core\Support\Install\PanelProviderTargetResolver;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class DiscoverFilamentPanelsAction
{
    use AsFake;
    use AsObject;

    /** @return Collection<int, AdminPanelCandidateData> */
    public function handle(): Collection
    {
        return resolve(PanelProviderTargetResolver::class)->candidates()->map(fn (PanelProviderData $panel): AdminPanelCandidateData => new AdminPanelCandidateData(
            path: $panel->path,
            relativePath: $panel->relativePath,
            className: $panel->className,
            panelId: $panel->panelId,
            alreadyIntegrated: str_contains((string) file_get_contents($panel->path), 'CapellAdminPlugin'),
            registered: $this->isRegistered($panel->className),
        ));
    }

    private function isRegistered(string $className): bool
    {
        $providerFiles = [
            base_path('bootstrap/providers.php'),
            config_path('app.php'),
        ];

        return array_any($providerFiles, fn (string $providerFile): bool => file_exists($providerFile) && str_contains((string) file_get_contents($providerFile), $className));
    }
}
