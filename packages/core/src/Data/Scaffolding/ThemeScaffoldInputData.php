<?php

declare(strict_types=1);

namespace Capell\Core\Data\Scaffolding;

use Illuminate\Support\Str;

final readonly class ThemeScaffoldInputData
{
    public function __construct(
        public string $packageName,
        public string $namespace,
        public string $slug,
        public string $themeKey,
        public string $displayName,
        public string $targetPath,
        public string $extends = 'default',
        public bool $local = true,
        public ?string $projectRoot = null,
        /** The package that declares the default-theme role; a scaffolded theme builds on it. */
        public ?string $baseThemePackage = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function stubReplacements(): array
    {
        return [
            '{{ packageName }}' => $this->packageName,
            '{{ slug }}' => $this->slug,
            '{{ themeKey }}' => $this->themeKey,
            '{{ displayName }}' => $this->displayName,
            '{{ namespace }}' => $this->namespace,
            '{{ escapedNamespace }}' => str_replace('\\', '\\\\', $this->namespace),
            '{{ providerClass }}' => $this->providerClass(),
            '{{ escapedRuntimeProvider }}' => str_replace('\\', '\\\\', $this->namespace . '\\' . $this->providerClass()),
            '{{ extends }}' => $this->extends,
            '{{ baseThemePackage }}' => $this->baseThemePackage ?? '',
            '{{ jsonRepositoryUrl }}' => $this->jsonStringValue($this->packageDirectory()),
            '{{ doctorPath }}' => $this->shellArgument($this->packageDirectory()),
            '{{ localRegistration }}' => $this->local ? 'true' : 'false',
            '{{ jsonDisplayName }}' => $this->jsonStringValue($this->displayName),
            '{{ jsonExtends }}' => $this->jsonStringValue($this->extends),
            '{{ jsonPackageName }}' => $this->jsonStringValue($this->packageName),
            '{{ jsonSlug }}' => $this->jsonStringValue($this->slug),
            '{{ jsonThemeKey }}' => $this->jsonStringValue($this->themeKey),
            '{{ phpDisplayName }}' => $this->phpStringValue($this->displayName),
            '{{ phpExtends }}' => $this->phpStringValue($this->extends),
            '{{ phpPackageName }}' => $this->phpStringValue($this->packageName),
            '{{ phpSlug }}' => $this->phpStringValue($this->slug),
            '{{ phpThemeKey }}' => $this->phpStringValue($this->themeKey),
        ];
    }

    /**
     * The theme directory as the host project should refer to it: relative to
     * the project root when inside it, otherwise the absolute path.
     */
    public function packageDirectory(): string
    {
        $target = $this->normalisedPath($this->targetPath);

        if ($this->projectRoot === null) {
            return $target;
        }

        $root = $this->normalisedPath($this->projectRoot);

        return str_starts_with($target, $root . '/') ? substr($target, strlen($root) + 1) : $target;
    }

    /**
     * The commands that take a freshly scaffolded theme from files on disk to a theme the
     * site can use, in the order to run them.
     *
     * @return array<int, string>
     */
    public function nextSteps(): array
    {
        $directory = $this->shellArgument($this->packageDirectory());

        return [
            sprintf('composer config repositories.%s path %s', $this->slug, $directory),
            // A path repository outside a Git checkout has no 1.x branch; Composer versions it as dev-main, which @dev accepts.
            sprintf('composer require %s:@dev', $this->packageName),
            'php artisan capell:package-cache:clear',
            'php artisan capell:frontend-tailwind-assets',
            sprintf('php artisan capell:install --theme=%s', $this->themeKey),
            sprintf('php artisan capell:theme:doctor %s --path=%s', $this->themeKey, $directory),
        ];
    }

    public function isOutsideProject(): bool
    {
        return $this->packageDirectory() === $this->normalisedPath($this->targetPath);
    }

    public function providerClass(): string
    {
        return Str::studly(Str::replaceEnd('-theme', '', $this->slug)) . 'ThemeServiceProvider';
    }

    private function normalisedPath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private function shellArgument(string $value): string
    {
        return preg_match('/\A[A-Za-z0-9._\/-]+\z/', $value) === 1 ? $value : escapeshellarg($value);
    }

    private function jsonStringValue(string $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return substr($encoded, 1, -1);
    }

    private function phpStringValue(string $value): string
    {
        return var_export($value, true);
    }
}
