<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Capell\Core\Enums\PackageScopeEnum;
use RuntimeException;
use Spatie\LaravelData\Data;

class ManifestData extends Data
{
    /**
     * @param  string[]  $dependsOn
     * @param  PackageScopeEnum[]  $scopes
     * @param  string[]  $afterInstallParams
     * @param  string[]  $setupParams
     * @param  string[]  $demoParams
     * @param  string[]  $fakerParams
     */
    public function __construct(
        public string $label,
        public string $slug,
        public string $kind = 'plugin',
        public int $order = 0,
        public array $dependsOn = [],
        public ?string $description = null,
        public ?string $icon = null,
        public ?string $url = null,
        public bool $core = false,
        public bool $demo = false,
        public array $scopes = [],
        public ?string $installCommand = null,
        public ?string $afterInstallCommand = null,
        public array $afterInstallParams = [],
        public ?string $setupCommand = null,
        public array $setupParams = [],
        public ?string $upgradeCommand = null,
        public ?string $demoCommand = null,
        public array $demoParams = [],
        public ?string $fakerCommand = null,
        public array $fakerParams = [],
    ) {}

    public static function fromFile(string $path): ?self
    {
        if (! is_file($path)) {
            throw new RuntimeException(sprintf('capell.json not found at %s', $path));
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read capell.json at %s', $path));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        // New-format capell.json files use 'name'/'capell-version' (CapellManifestData).
        // Old-format files use 'label'/'slug'. Return null for new-format files.
        if (! isset($decoded['label'])) {
            return null;
        }

        $commands = is_array($decoded['commands'] ?? null) ? $decoded['commands'] : [];
        $scopes = array_map(
            PackageScopeEnum::from(...),
            is_array($decoded['scopes'] ?? null) ? $decoded['scopes'] : [],
        );

        return new self(
            label: (string) $decoded['label'],
            slug: (string) $decoded['slug'],
            kind: isset($decoded['kind']) ? (string) $decoded['kind'] : 'plugin',
            order: isset($decoded['order']) ? (int) $decoded['order'] : 0,
            dependsOn: is_array($decoded['dependsOn'] ?? null) ? array_values($decoded['dependsOn']) : [],
            description: isset($decoded['description']) ? (string) $decoded['description'] : null,
            icon: isset($decoded['icon']) ? (string) $decoded['icon'] : null,
            url: isset($decoded['url']) ? (string) $decoded['url'] : null,
            core: (bool) ($decoded['core'] ?? false),
            demo: (bool) ($decoded['demo'] ?? false),
            scopes: $scopes,
            installCommand: isset($commands['install']) ? (string) $commands['install'] : null,
            afterInstallCommand: isset($commands['afterInstall']) ? (string) $commands['afterInstall'] : null,
            afterInstallParams: is_array($commands['afterInstallParams'] ?? null) ? array_values($commands['afterInstallParams']) : [],
            setupCommand: isset($commands['setup']) ? (string) $commands['setup'] : null,
            setupParams: is_array($commands['setupParams'] ?? null) ? array_values($commands['setupParams']) : [],
            upgradeCommand: isset($commands['upgrade']) ? (string) $commands['upgrade'] : null,
            demoCommand: isset($commands['demo']) ? (string) $commands['demo'] : null,
            demoParams: is_array($commands['demoParams'] ?? null) ? array_values($commands['demoParams']) : [],
            fakerCommand: isset($commands['faker']) ? (string) $commands['faker'] : null,
            fakerParams: is_array($commands['fakerParams'] ?? null) ? array_values($commands['fakerParams']) : [],
        );
    }
}
