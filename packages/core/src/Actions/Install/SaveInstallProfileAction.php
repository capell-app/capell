<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\InstallProfileRepository;
use Capell\Core\Support\Install\InstallSiteUrl;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class SaveInstallProfileAction
{
    use AsObject;

    public function handle(InstallInputData $input, string $name, bool $buildAssets = false): void
    {
        $lock = fopen(storage_path('capell-install-profiles.lock'), 'c');
        throw_if($lock === false, RuntimeException::class, 'Unable to lock the installation profile file.');
        try {
            throw_unless(flock($lock, LOCK_EX), RuntimeException::class, 'Unable to lock the installation profile file.');
            $this->save($input, $name, $buildAssets);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function save(InstallInputData $input, string $name, bool $buildAssets): void
    {
        throw_unless(preg_match('/^[a-z0-9][a-z0-9-]*$/', $name) === 1, RuntimeException::class, 'Use a lowercase profile name containing letters, numbers and hyphens.');
        throw_if(resolve(InstallProfileRepository::class)->find($name) !== null, RuntimeException::class, 'An installation profile with this name already exists. Choose another name.');
        $path = base_path('capell-install-profiles.json');
        $profiles = is_file($path) ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
        throw_unless(is_array($profiles) && ($profiles === [] || ! array_is_list($profiles)), RuntimeException::class, 'The installation profile file must contain a JSON object.');
        $profiles[$name] = [
            'packages' => array_values(array_unique([...$input->packages, ...$input->extraPackages])),
            'theme' => $input->selectedThemeKey,
            'site_url' => InstallSiteUrl::publicUrl($input->siteUrl),
            'demo' => $input->demoContent,
            'seed_default_data' => $input->seedDefaultData,
            'seed_database' => $input->seedDatabase,
            'build_assets' => $buildAssets,
            'languages' => $input->languages,
            'sites' => $input->demoSites ?? [],
        ];
        $temporary = tempnam(dirname($path), '.capell-profile-');
        throw_if($temporary === false, RuntimeException::class, 'Unable to create the installation profile file.');
        try {
            $json = json_encode($profiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            throw_if(file_put_contents($temporary, $json, LOCK_EX) === false || ! rename($temporary, $path), RuntimeException::class, 'Unable to save the installation profile file.');
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
