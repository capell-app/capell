<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Widgets;

use Capell\Admin\Contracts\Widgets\FilamentWidget;
use Filament\Forms\Components\Builder\Block;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use ReflectionClass;
use Throwable;

class WidgetDiscovery
{
    /** @var array<int, array{directory: string, namespace: string}> */
    private array $discoverableWidgets = [];

    /** @var array<string, class-string> */
    private array $widgets = [];

    /** @var array<string, true> */
    private array $authoritativeWidgets = [];

    private int $discoveredSourceCount = 0;

    private ?bool $hasCachedWidgets = null;

    public function __construct(
        private readonly ?Filesystem $filesystem = null,
    ) {}

    /**
     * @param  class-string  $widgetClass
     */
    public function register(string $widgetClass): void
    {
        $widgetName = $this->widgetName($widgetClass);

        if (isset($this->authoritativeWidgets[$widgetName])) {
            return;
        }

        $this->widgets[$widgetName] = $widgetClass;
    }

    /**
     * Register a canonical widget which ordinary discovery and cache restores
     * cannot replace for the lifetime of this registry instance.
     *
     * @param  class-string  $widgetClass
     */
    public function registerAuthoritative(string $widgetClass): void
    {
        $widgetName = $this->widgetName($widgetClass);

        if (isset($this->authoritativeWidgets[$widgetName])) {
            return;
        }

        $this->widgets[$widgetName] = $widgetClass;
        $this->authoritativeWidgets[$widgetName] = true;
    }

    public function registerDiscoverableWidgets(string $directory, string $namespace): void
    {
        $this->discoverableWidgets[] = [
            'directory' => $directory,
            'namespace' => trim($namespace, '\\'),
        ];
    }

    /**
     * @return list<Block>
     */
    public function filamentWidgets(): array
    {
        $this->discoverWidgets();

        return array_values(collect($this->widgets)
            ->map(fn (string $widgetClass): Block => $widgetClass::make())
            ->values()
            ->all());
    }

    /**
     * @return array<string, class-string>
     */
    public function registeredWidgets(): array
    {
        $this->discoverWidgets();

        return $this->widgets;
    }

    public function hasCachedWidgets(): bool
    {
        if ($this->hasCachedWidgets !== null) {
            return $this->hasCachedWidgets;
        }

        return $this->hasCachedWidgets = ! app()->runningInConsole()
            && $this->filesystem()->exists($this->getWidgetCachePath());
    }

    public function cacheWidgets(): void
    {
        $this->discoverWidgets();

        $this->filesystem()->ensureDirectoryExists(dirname($this->getWidgetCachePath()));
        $this->filesystem()->put(
            $this->getWidgetCachePath(),
            '<?php return ' . var_export($this->widgets, true) . ';',
        );
    }

    public function restoreCachedWidgets(): void
    {
        if (! $this->filesystem()->exists($this->getWidgetCachePath())) {
            return;
        }

        $cachedWidgets = require $this->getWidgetCachePath();

        if (! is_array($cachedWidgets)) {
            return;
        }

        foreach ($cachedWidgets as $name => $widgetClass) {
            if (! is_string($name)) {
                continue;
            }

            if (! is_string($widgetClass)) {
                continue;
            }

            if (! class_exists($widgetClass)) {
                continue;
            }

            if (! isset($this->authoritativeWidgets[$name])) {
                $this->widgets[$name] = $widgetClass;
            }
        }
    }

    public function clearCachedWidgets(): void
    {
        $this->hasCachedWidgets = false;
        $this->discoveredSourceCount = 0;

        if ($this->filesystem()->exists($this->getWidgetCachePath())) {
            $this->filesystem()->delete($this->getWidgetCachePath());
        }
    }

    public function getWidgetCachePath(): string
    {
        return app()->bootstrapPath('cache/capell-widgets.php');
    }

    private function discoverWidgets(): void
    {
        if ($this->discoveredSourceCount === count($this->discoverableWidgets)) {
            return;
        }

        if ($this->hasCachedWidgets()) {
            $this->restoreCachedWidgets();
            $this->discoveredSourceCount = count($this->discoverableWidgets);

            return;
        }

        foreach (array_slice($this->discoverableWidgets, $this->discoveredSourceCount) as $source) {
            if (! $this->filesystem()->isDirectory($source['directory'])) {
                continue;
            }

            foreach ($this->filesystem()->allFiles($source['directory']) as $file) {
                $relativePath = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
                $widgetClass = $source['namespace'] . '\\' . $relativePath;

                if (! class_exists($widgetClass)) {
                    continue;
                }

                try {
                    $this->register($widgetClass);
                } catch (Throwable) {
                    continue;
                }
            }
        }

        $this->discoveredSourceCount = count($this->discoverableWidgets);
    }

    private function filesystem(): Filesystem
    {
        return $this->filesystem ?? resolve(Filesystem::class);
    }

    /**
     * @param  class-string  $widgetClass
     */
    private function widgetName(string $widgetClass): string
    {
        if (! class_exists($widgetClass)) {
            throw new InvalidArgumentException(sprintf('Widget class [%s] does not exist.', $widgetClass));
        }

        $reflection = new ReflectionClass($widgetClass);

        if ($reflection->implementsInterface(FilamentWidget::class)) {
            return $widgetClass::getWidgetName();
        }

        throw new InvalidArgumentException(sprintf(
            'Widget class [%s] must implement [%s].',
            $widgetClass,
            FilamentWidget::class,
        ));
    }
}
