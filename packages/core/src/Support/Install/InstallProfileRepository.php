<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Data\Install\InstallProfileData;
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
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function profiles(): array
    {
        $configProfiles = config('capell.install_profiles');

        if (is_array($configProfiles)) {
            return $this->normaliseProfiles($configProfiles);
        }

        $phpPath = base_path('config/capell-install-profiles.php');

        if (File::exists($phpPath)) {
            $profiles = require $phpPath;

            if (is_array($profiles)) {
                return $this->normaliseProfiles($profiles);
            }
        }

        $jsonPath = base_path('capell-install-profiles.json');

        if (! File::exists($jsonPath)) {
            return [];
        }

        try {
            $profiles = json_decode((string) File::get($jsonPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($profiles) ? $this->normaliseProfiles($profiles) : [];
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
