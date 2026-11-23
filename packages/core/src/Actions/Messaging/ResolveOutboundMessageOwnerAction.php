<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Messaging;

use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Manifest\ManifestLoader;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Names the package that owns a message class from the namespaces the installed manifests declare,
 * longest prefix first. The host application's `App\` namespace is `app`.
 *
 * @method static string|null run(?string $class, array<string, CapellManifestData>|null $manifests = null)
 */
class ResolveOutboundMessageOwnerAction
{
    use AsFake;
    use AsObject;

    public const string APPLICATION = 'app';

    /** @var array<string, string>|null namespace prefix => package name */
    private ?array $prefixes = null;

    public function __construct(
        private readonly ManifestLoader $manifestLoader,
    ) {}

    /** @param  array<string, CapellManifestData>|null  $manifests */
    public function handle(?string $class, ?array $manifests = null): ?string
    {
        if ($class === null || $class === '') {
            return null;
        }

        $class = ltrim($class, '\\');

        foreach ($this->prefixes($manifests) as $prefix => $package) {
            if (str_starts_with($class, $prefix)) {
                return $package;
            }
        }

        return null;
    }

    /**
     * @param  array<string, CapellManifestData>|null  $manifests
     * @return array<string, string>
     */
    private function prefixes(?array $manifests): array
    {
        if ($manifests === null && $this->prefixes !== null) {
            return $this->prefixes;
        }

        $prefixes = ['App\\' => self::APPLICATION];

        foreach ($manifests ?? $this->manifestLoader->discover() as $manifest) {
            $namespace = $manifest->resolvedNamespace();

            if ($namespace !== null && $namespace !== '') {
                $prefixes[rtrim($namespace, '\\') . '\\'] = $manifest->name;
            }
        }

        uksort($prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        if ($manifests !== null) {
            return $prefixes;
        }

        return $this->prefixes = $prefixes;
    }
}
