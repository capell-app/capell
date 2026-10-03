<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Page;

use Capell\Admin\Filament\Components\Forms\NameInput as BaseNameInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;
use Override;

class NameInput extends BaseNameInput
{
    #[Override]
    public function withTitleUpdater(): self
    {
        return $this->afterStateUpdated(function (Get $get, Set $set, ?string $state, string $operation): void {
            if (blank($state)) {
                return;
            }

            if (! in_array($operation, ['create', 'createOption', 'replicate'], true)) {
                return;
            }

            $translations = $get('translations');

            if (! is_array($translations)) {
                return;
            }

            foreach ($translations as $key => $translation) {
                if (! is_array($translation)) {
                    continue;
                }

                if (filled($translation['title'] ?? null)) {
                    continue;
                }

                // Deferred updates already contain the submitted public title.
                // The internal name is only an initial default for blank fields.
                $set(sprintf('translations.%s.title', $key), $state);

                $isChangedManually = (bool) ($translation['slug_auto_update_disabled'] ?? false)
                    || (bool) ($translation['is_slug_changed_manually'] ?? false);
                if (! $isChangedManually && blank(data_get($translation, 'meta.slug'))) {
                    $set(sprintf('translations.%s.meta.slug', $key), Str::slug($state));
                }
            }
        })
            // A state watcher runs on every keystroke; seed the completed input.
            ->extraInputAttributes(function (string $operation): array {
                if (! in_array($operation, ['create', 'createOption', 'replicate'], true)) {
                    return [];
                }

                return ['x-on:change' => <<<'JS'
                    if ($state?.trim()) {
                        const translations = $get('translations') ?? {};
                        for (const [key, translation] of Object.entries(translations)) {
                            if (translation && !(translation.title ?? '').trim()) {
                                $set(`translations.${key}.title`, $state);
                            }
                        }
                    }
                JS];
            }, merge: true);
    }
}
