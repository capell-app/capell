<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Themes;

use Spatie\LaravelData\Data;

final class ThemeDiagnosticsData extends Data
{
    /**
     * @param  list<string>  $warnings
     * @param  list<string>  $errors
     * @param  list<string>  $missingSections
     * @param  list<string>  $missingAssets
     */
    public function __construct(
        public string $themeKey,
        public bool $installed,
        public bool $hasDefinition,
        public bool $hasRenderer,
        public bool $extendsResolved,
        public bool $hasPresets,
        public bool $hasPreviewImage,
        public array $warnings = [],
        public array $errors = [],
        public array $missingSections = [],
        public array $missingAssets = [],
    ) {}

    public function hasWarnings(): bool
    {
        return $this->warnings !== [] || $this->errors !== [];
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function badgeLabel(): string
    {
        if ($this->errors !== []) {
            return (string) __('capell-admin::theme-library.labels.diagnostics_error');
        }

        if ($this->warnings !== []) {
            return (string) __('capell-admin::theme-library.labels.diagnostics_warning');
        }

        return (string) __('capell-admin::theme-library.labels.diagnostics_clean');
    }

    public function badgeColor(): string
    {
        if ($this->errors !== []) {
            return 'danger';
        }

        if ($this->warnings !== []) {
            return 'warning';
        }

        return 'success';
    }
}
