<?php

declare(strict_types=1);

namespace Capell\Core\Data\Scaffolding;

use Capell\Core\Support\Json\JsonCodec;
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

    public function providerClass(): string
    {
        return Str::studly(Str::replaceEnd('-theme', '', $this->slug)) . 'ThemeServiceProvider';
    }

    private function jsonStringValue(string $value): string
    {
        $encoded = JsonCodec::encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return substr($encoded, 1, -1);
    }

    private function phpStringValue(string $value): string
    {
        return var_export($value, true);
    }
}
