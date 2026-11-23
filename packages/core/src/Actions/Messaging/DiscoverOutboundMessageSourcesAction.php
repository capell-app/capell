<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Messaging;

use Capell\Core\Data\Messaging\OutboundMessageSourceData;
use Capell\Core\Enums\Messaging\OutboundMessageKind;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Manifest\ManifestLoader;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Lists the Mailable and Notification classes in installed Capell packages and the host
 * application, so they can be catalogued before they are first sent.
 *
 * Only each installed package's own PSR-4 source directories (from its composer.json) and `app/`
 * are read; the rest of `vendor/` is not scanned. A file is loaded only when its text mentions
 * Mailable or Notification. A Mailable goes out on `mail`. A Notification's channels come from
 * `via()`, which needs a recipient, so its channel stays unknown until it is first sent; no
 * recipient is ever invented to find out.
 *
 * @method static list<OutboundMessageSourceData> run(array<string, CapellManifestData>|null $manifests = null)
 */
class DiscoverOutboundMessageSourcesAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly ManifestLoader $manifestLoader,
    ) {}

    /**
     * @param  array<string, CapellManifestData>|null  $manifests
     * @return list<OutboundMessageSourceData>
     */
    public function handle(?array $manifests = null): array
    {
        $sources = [];

        foreach ($this->roots($manifests ?? $this->manifestLoader->discover()) as [$package, $prefix, $directory]) {
            foreach ($this->classesIn($prefix, $directory) as $class) {
                $source = $this->sourceFor($class, $package);

                if ($source instanceof OutboundMessageSourceData) {
                    $sources[$source->key()] = $source;
                }
            }
        }

        ksort($sources);

        return array_values($sources);
    }

    /**
     * @param  array<string, CapellManifestData>  $manifests
     * @return list<array{0: string, 1: string, 2: string}> package name, namespace prefix, directory
     */
    private function roots(array $manifests): array
    {
        $roots = [];
        $appPath = app_path();

        if (is_dir($appPath)) {
            $roots[] = [ResolveOutboundMessageOwnerAction::APPLICATION, 'App\\', $appPath];
        }

        foreach ($manifests as $manifest) {
            if ($manifest->installPath === null) {
                continue;
            }

            if (! CapellCore::isPackageInstalled($manifest->name)) {
                continue;
            }

            foreach ($this->psr4($manifest->installPath) as $prefix => $directory) {
                $roots[] = [$manifest->name, $prefix, $directory];
            }
        }

        return $roots;
    }

    /** @return array<string, string> */
    private function psr4(string $installPath): array
    {
        $composerPath = rtrim($installPath, '/') . '/composer.json';

        if (! is_file($composerPath)) {
            return [];
        }

        $composer = json_decode((string) file_get_contents($composerPath), true);
        $map = is_array($composer) ? ($composer['autoload']['psr-4'] ?? []) : [];
        $roots = [];

        if (! is_array($map)) {
            return [];
        }

        foreach ($map as $prefix => $paths) {
            foreach ((array) $paths as $path) {
                $directory = rtrim($installPath, '/') . '/' . trim((string) $path, '/');

                if (is_string($prefix) && is_dir($directory) && ! str_contains($prefix, 'Database\\')) {
                    $roots[$prefix] = $directory;
                }
            }
        }

        return $roots;
    }

    /** @return list<string> */
    private function classesIn(string $prefix, string $directory): array
    {
        $classes = [];
        $finder = Finder::create()->files()->name('*.php')->in($directory)->ignoreUnreadableDirs();

        foreach ($finder as $file) {
            $contents = $file->getContents();

            if (! str_contains($contents, 'Mailable') && ! str_contains($contents, 'Notification')) {
                continue;
            }

            $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $classes[] = rtrim($prefix, '\\') . '\\' . $relative;
        }

        return $classes;
    }

    private function sourceFor(string $class, string $package): ?OutboundMessageSourceData
    {
        try {
            if (! class_exists($class)) {
                return null;
            }

            $reflection = new ReflectionClass($class);
        } catch (Throwable) {
            return null;
        }

        if (! $reflection->isInstantiable()) {
            return null;
        }

        if ($reflection->isSubclassOf(Mailable::class)) {
            return new OutboundMessageSourceData(OutboundMessageKind::Mailable, $class, 'mail', $package);
        }

        if ($reflection->isSubclassOf(Notification::class)) {
            return new OutboundMessageSourceData(OutboundMessageKind::Notification, $class, null, $package);
        }

        return null;
    }
}
