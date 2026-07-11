<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Enums\PresentationLoadingStrategy;
use ReflectionProperty;
use Spatie\LaravelData\Data;

class FrontendAssetRequirementData extends Data
{
    public const string KIND_CSS = 'css';

    public const string KIND_JS = 'js';

    public const string KIND_INLINE = 'inline';

    public const string KIND_PRELOAD = 'preload';

    public const string KIND_MODULEPRELOAD = 'modulepreload';

    public function __construct(
        public string $handle,
        public string $kind,
        public string $source,
        public ?string $buildPath = null,
        public bool $defer = false,
        public bool $async = false,
        public ?string $condition = null,
        public PresentationLoadingStrategy $loadingStrategy = PresentationLoadingStrategy::Eager,
        public bool $module = true,
    ) {}

    public function isCss(): bool
    {
        return $this->kind === self::KIND_CSS;
    }

    public function isJavaScript(): bool
    {
        return $this->kind === self::KIND_JS;
    }

    public function isInline(): bool
    {
        return $this->kind === self::KIND_INLINE;
    }

    public function isPreload(): bool
    {
        return in_array($this->kind, [self::KIND_PRELOAD, self::KIND_MODULEPRELOAD], true);
    }

    public function usesModuleScript(): bool
    {
        if (! new ReflectionProperty($this, 'module')->isInitialized($this)) {
            return true;
        }

        return $this->module;
    }

    public function isBuildAsset(): bool
    {
        return $this->buildPath !== null && ($this->isCss() || $this->isJavaScript());
    }
}
