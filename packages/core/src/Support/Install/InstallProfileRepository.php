<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Data\Install\InstallProfileData;
use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Support\Facades\File;
use Throwable;

final class InstallProfileRepository
{
    public function find(?string $key): ?InstallProfileData
    {
        if ($key === null || $key === '') {
            return null;
        }

        $profiles = $this->profiles();
        $profile = $profiles[$key] ?? null;

        if (! is_array($profile)) {
            return null;
        }

        return new InstallProfileData(
            key: $key,
            packages: $this->stringList($profile['packages'] ?? []),
            theme: is_string($profile['theme'] ?? null) && $profile['theme'] !== '' ? $profile['theme'] : null,
            demo: is_bool($profile['demo'] ?? null) ? $profile['demo'] : null,
            languages: $this->stringList($profile['languages'] ?? []),
            sites: $this->stringList($profile['sites'] ?? []),
            siteUrl: is_string($profile['site_url'] ?? null) ? $profile['site_url'] : null,
            seedDefaultData: is_bool($profile['seed_default_data'] ?? null) ? $profile['seed_default_data'] : null,
            seedDatabase: is_bool($profile['seed_database'] ?? null) ? $profile['seed_database'] : null,
            buildAssets: is_bool($profile['build_assets'] ?? null) ? $profile['build_assets'] : null,
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function profiles(): array
    {
        $jsonPath = base_path('capell-install-profiles.json');
        $profiles = [];
        if (File::exists($jsonPath)) {
            try {
                $profiles = $this->normaliseProfiles(JsonCodec::decodeArray((string) File::get($jsonPath)));
            } catch (Throwable) {
                // Invalid JSON does not hide configured PHP profiles.
            }
        }

        $phpPath = base_path('config/capell-install-profiles.php');
        if (File::exists($phpPath)) {
            $phpProfiles = require $phpPath;
            if (is_array($phpProfiles)) {
                $profiles = array_replace($profiles, $this->normaliseProfiles($phpProfiles));
            }
        }

        $configProfiles = config('capell.install_profiles');

        return is_array($configProfiles)
            ? array_replace($profiles, $this->normaliseProfiles($configProfiles))
            : $profiles;
    }

    /**
     * @param  array<mixed>  $profiles
     * @return array<string, array<string, mixed>>
     */
    private function normaliseProfiles(array $profiles): array
    {
        return collect($profiles)
            ->filter(fn (mixed $profile, mixed $key): bool => is_string($key) && is_array($profile))
            ->mapWithKeys(fn (array $profile, string $key): array => [$key => $profile])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $value),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
