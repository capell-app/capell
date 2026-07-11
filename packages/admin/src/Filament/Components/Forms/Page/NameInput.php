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

            if ($translations === null || $translations === []) {
                return;
            }

            $key = array_key_first($translations);

            $translation = $translations[$key];

            $set(sprintf('translations.%s.title', $key), $state);

            $isChangedManually = (bool) ($translation['is_slug_changed_manually'] ?? false);
            $slug = $translation['slug'] ?? null;
            if (! $isChangedManually && ($slug === null || $slug === '')) {
                $set(sprintf('translations.%s.slug', $key), Str::slug($state));
            }
        })
            ->afterStateUpdatedJs(function (string $operation): string {
                if (! in_array($operation, ['create', 'createOption', 'replicate'], true)) {
                    return '';
                }

                return <<<'JS'
                    if ($state) {
                        const translations = $get('translations');
                        if (translations) {
                            for (const key in translations) {
                                if (translations[key].is_slug_changed_manually) {
                                    continue;
                                }

                                const title = $state
                                    .normalize('NFD')
                                    .replace(/[\u0300-\u036f]/g, '')
                                    .replace(/-/g, ' ')
                                    .replace(/\b\w/g, c => c.toUpperCase());

                                $set(`translations.${key}.title`, title);
                                setTimeout(() => $set(`translations.${key}.is_slug_changed_manually`, false), 0);
                            }
                        }
                    }
                JS;
            });
    }
}
