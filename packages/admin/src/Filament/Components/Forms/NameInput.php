<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Override;

class NameInput extends TextInput
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.name'))
            ->required()
            ->hint(__('capell-admin::generic.internal'))
            ->autofocus(fn (string $operation): bool => in_array($operation, ['create', 'createOption', 'replicate'], true));
    }

    public function withTitleUpdater(): self
    {
        // A state watcher runs on every keystroke; seed the completed input.
        return $this->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
            if (blank($state)) {
                return;
            }

            $translations = $get('translations');
            if (! is_array($translations)) {
                return;
            }

            $key = array_key_first($translations);
            if ($key === null) {
                return;
            }

            $translation = $translations[$key];
            if (! is_array($translation)) {
                return;
            }

            if (filled($translation['title'] ?? null)) {
                return;
            }

            $set(sprintf('translations.%s.title', $key), $state);
        })->extraInputAttributes(['x-on:change' => <<<'JS'
            if ($state?.trim()) {
                const translations = $get('translations') ?? {};
                const key = Object.keys(translations)[0];
                if (
                    key !== undefined
                    && !(translations[key].title ?? '').trim()
                ) {
                    $set('translations.' + key + '.title', $state);
                }
            }
        JS], merge: true);
    }
}
