<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Site;

use Capell\Admin\Filament\Components\Forms\LanguageSelect;
use Capell\Core\Models\SiteDomain;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;

class DomainsSchema
{
    /** @return array<int, Hidden|Grid> */
    public static function make(): array
    {
        return [
            Hidden::make('default')
                ->default(function (?bool $state, Hidden $component, Get $get): bool {
                    if ($state !== null) {
                        return $state;
                    }

                    $hasDefault = collect((array) $get('../'))->firstWhere('default', true);

                    if ($hasDefault === null) {
                        $component->state(true);

                        return true;
                    }

                    return false;
                }),
            Grid::make(['default' => 1, 'md' => 3])
                ->columnSpanFull()
                ->schema([
                    TextInput::make('url')
                        ->label(__('capell-admin::form.full_url'))
                        ->required()
                        ->url()
                        ->hint(__('capell-admin::generic.full_url_helper'))
                        ->validationAttribute(__('capell-admin::form.url'))
                        ->columnSpan(['default' => 1, 'md' => 2])
                        ->rules([
                            fn (string $context, ?SiteDomain $record): Closure => function (string $attribute, string $value, Closure $fail) use ($record) {
                                $urlParts = parse_url($value);

                                if ($urlParts === false || ! isset($urlParts['scheme'], $urlParts['host'])) {
                                    return $fail(__('capell-admin::message.site_domain_invalid'));
                                }

                                if (! in_array($urlParts['scheme'], ['http', 'https'], true)) {
                                    return $fail(__('capell-admin::message.site_domain_scheme_invalid'));
                                }

                                $path = $urlParts['path'] ?? null;
                                if ($path === '/') {
                                    $path = null;
                                }

                                $appUrlHost = parse_url((string) config('app.url'), PHP_URL_HOST);

                                $siteDomain = SiteDomain::query()->withWhereHas('site')
                                    ->whereHas('language')
                                    ->where(
                                        fn (Builder $query) => $query
                                            ->whereNull('scheme')
                                            ->orWhere('scheme', $urlParts['scheme']),
                                    )
                                    ->where(
                                        fn (Builder $query): Builder => $query
                                            ->where('domain', $urlParts['host'])
                                            ->when(
                                                $urlParts['host'] === $appUrlHost,
                                                fn (Builder $query): Builder => $query->orWhereNull('domain'),
                                            ),
                                    )
                                    ->where('path', $path);

                                if ($record instanceof SiteDomain) {
                                    $siteDomain->whereKeyNot($record->id);
                                }

                                $existingSiteDomain = $siteDomain->first();

                                if ($existingSiteDomain instanceof SiteDomain) {
                                    return $fail(
                                        __('capell-admin::message.site_domain_taken_by_site', [
                                            'site' => $existingSiteDomain->site->name,
                                        ]),
                                    );
                                }

                            },
                        ])
                        ->suffixAction(
                            Action::make('toggleDefault')
                                ->icon('heroicon-c-check')
                                ->color(fn (Get $get): string => ((bool) $get('default')) ? 'success' : 'gray')
                                ->label(__('capell-admin::form.default_toggle'))
                                ->tooltip(
                                    fn (Get $get): string => (bool) $get('default')
                                        ? __('capell-admin::form.default_toggle_label', ['label' => __('capell-admin::generic.no')])
                                        : __('capell-admin::form.default_toggle_label', ['label' => __('capell-admin::generic.yes')]),
                                )
                                ->visible(function (Get $get): bool {
                                    if ((bool) $get('default')) {
                                        return true;
                                    }

                                    return collect((array) $get('../'))->where('default', true)->isEmpty();
                                })
                                ->action(function (Set $set, Get $get): void {
                                    $set('default', ! (bool) $get('default'));
                                }),
                        )
                        ->default(fn (string $operation, Get $get): string => request()->schemeAndHttpHost()),

                    LanguageSelect::make('language_id')
                        ->withRelationship()
                        ->modifyRelationQueryUsing(function (Builder $query, string $operation, Get $get): Builder {
                            if (in_array($operation, ['create', 'createOption', 'replicate'], true)) {
                                $languages = [$get('../../language_id')];

                                $moreLanguages = $get('../../languages');
                                if (is_array($moreLanguages) && $moreLanguages !== []) {
                                    $languages = array_merge($languages, $moreLanguages);
                                }

                                return $query->whereKey($languages);
                            }

                            return $query;
                        })
                        ->required()
                        ->selectablePlaceholder(false),
                ]),
        ];
    }
}
