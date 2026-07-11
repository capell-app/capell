<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Themes;

use Illuminate\Contracts\Translation\Translator;

final readonly class ThemeEditorLabelResolver
{
    public function __construct(private Translator $translator) {}

    public function resolve(?string $declaredLabel, string $fallbackKey): string
    {
        if (is_string($declaredLabel) && trim($declaredLabel) !== '') {
            return $this->translatedOrLiteral($declaredLabel);
        }

        $translationKey = 'capell-admin::theme-editor.schema_labels.' . str($fallbackKey)->snake();

        if ($this->translator->has($translationKey)) {
            return $this->translator->get($translationKey);
        }

        return str($fallbackKey)->headline()->toString();
    }

    private function translatedOrLiteral(string $label): string
    {
        return $this->translator->has($label) ? $this->translator->get($label) : $label;
    }
}
