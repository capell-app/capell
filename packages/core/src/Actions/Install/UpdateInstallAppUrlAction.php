<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Support\Install\InstallSiteUrl;
use Capell\Core\Support\Patching\EnvFileEditor;
use Lorisleiva\Actions\Concerns\AsObject;

final class UpdateInstallAppUrlAction
{
    use AsObject;

    public function handle(string $siteUrl, ?string $path = null): void
    {
        $appUrl = InstallSiteUrl::publicUrl($siteUrl);
        $editor = new EnvFileEditor($path ?? base_path('.env'));
        $editor->backup();
        $editor->set('APP_URL', $appUrl)->save();
        config(['app.url' => $appUrl]);
    }
}
