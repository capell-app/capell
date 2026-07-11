<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Site\Wizard;

use Capell\Core\Models\Language;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Override;

class DetailsStep extends Step
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('capell-admin::tab.details'))
            ->afterValidation(function (Get $get, Set $set): void {
                $languages = array_merge([$get('language_id')], (array) $get('languages'));

                $domains = Language::query()->whereIn('id', $languages)
                    ->orderByDesc('default')
                    ->get()
                    ->map(fn (Language $language, int $index): array => [
                        'url' => request()->schemeAndHttpHost() . ($language->default ? '' : '/' . $language->code),
                        'language_id' => $language->id,
                        'default' => $index === 0,
                        'use_host_domain' => true,
                    ]);

                $set('site_domains', $domains->all());
            });
    }
}
