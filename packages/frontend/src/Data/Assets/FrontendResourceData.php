<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Frontend\Contracts\FrontendResourceSourceData;
use Capell\Frontend\Enums\FrontendResourceKind;
use Capell\Frontend\Enums\FrontendResourcePlacement;
use Capell\Frontend\Enums\ScriptExecutionMode;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

final class FrontendResourceData extends Data
{
    /**
     * @param  array<int, string>  $dependsOn
     */
    public function __construct(
        public readonly string $handle,
        public readonly string $package,
        public readonly FrontendResourceKind $kind,
        public readonly FrontendResourceSourceData $source,
        public readonly PresentationLoadingStrategy $loadingStrategy = PresentationLoadingStrategy::Eager,
        public readonly FrontendResourcePlacement $placement = FrontendResourcePlacement::Head,
        public readonly array $dependsOn = [],
        public readonly bool $criticalCssEligible = false,
        public readonly ?ScriptExecutionMode $executionMode = null,
        public readonly bool $defer = false,
        public readonly bool $async = false,
    ) {
        $this->validateDeclaration();
    }

    public static function style(
        string $handle,
        string $package,
        FrontendResourceSourceData $source,
        PresentationLoadingStrategy $loadingStrategy = PresentationLoadingStrategy::Eager,
        FrontendResourcePlacement $placement = FrontendResourcePlacement::Head,
        array $dependsOn = [],
        bool $criticalCssEligible = false,
    ): self {
        return new self($handle, $package, FrontendResourceKind::Style, $source, $loadingStrategy, $placement, $dependsOn, $criticalCssEligible);
    }

    public static function moduleScript(
        string $handle,
        string $package,
        FrontendResourceSourceData $source,
        PresentationLoadingStrategy $loadingStrategy = PresentationLoadingStrategy::Eager,
        FrontendResourcePlacement $placement = FrontendResourcePlacement::Head,
        array $dependsOn = [],
        bool $async = false,
    ): self {
        return new self($handle, $package, FrontendResourceKind::ModuleScript, $source, $loadingStrategy, $placement, $dependsOn, false, ScriptExecutionMode::Module, false, $async);
    }

    public static function classicScript(
        string $handle,
        string $package,
        FrontendResourceSourceData $source,
        PresentationLoadingStrategy $loadingStrategy = PresentationLoadingStrategy::Eager,
        FrontendResourcePlacement $placement = FrontendResourcePlacement::Head,
        array $dependsOn = [],
        bool $defer = true,
        bool $async = false,
    ): self {
        return new self($handle, $package, FrontendResourceKind::ClassicScript, $source, $loadingStrategy, $placement, $dependsOn, false, ScriptExecutionMode::Classic, $defer, $async);
    }

    public static function inlineStyle(
        string $handle,
        string $package,
        string $content,
        PresentationLoadingStrategy $loadingStrategy = PresentationLoadingStrategy::Eager,
        array $dependsOn = [],
        bool $criticalCssEligible = false,
    ): self {
        return new self($handle, $package, FrontendResourceKind::InlineStyle, new InlineResourceSourceData($content), $loadingStrategy, FrontendResourcePlacement::Head, $dependsOn, $criticalCssEligible);
    }

    public static function inlineScript(
        string $handle,
        string $package,
        string $content,
        PresentationLoadingStrategy $loadingStrategy = PresentationLoadingStrategy::Eager,
        FrontendResourcePlacement $placement = FrontendResourcePlacement::BodyEnd,
        array $dependsOn = [],
    ): self {
        return new self($handle, $package, FrontendResourceKind::InlineScript, new InlineResourceSourceData($content), $loadingStrategy, $placement, $dependsOn, false, ScriptExecutionMode::Classic);
    }

    private function validateDeclaration(): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._\/-]*:[A-Za-z0-9][A-Za-z0-9._\/-]*\z/', $this->handle) !== 1) {
            throw new InvalidArgumentException('Frontend resource handle must be globally stable and package-qualified.');
        }

        if (preg_match('/\A[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?\/[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?\z/', $this->package) !== 1) {
            throw new InvalidArgumentException('Frontend resource package must be a valid Composer package name.');
        }

        if ($this->kind->isInline() !== $this->source instanceof InlineResourceSourceData) {
            throw new InvalidArgumentException('Inline resource kinds require inline sources and non-inline kinds require URL-backed sources.');
        }

        if (! $this->kind->isScript() && ($this->executionMode !== null || $this->defer || $this->async)) {
            throw new InvalidArgumentException('Script attributes may only be declared for script resources.');
        }

        if ($this->async && $this->dependsOn !== []) {
            throw new InvalidArgumentException('Async resources cannot declare dependencies.');
        }

        if (count($this->dependsOn) !== count(array_unique($this->dependsOn))) {
            throw new InvalidArgumentException('Frontend resource dependencies must be unique.');
        }
    }
}
