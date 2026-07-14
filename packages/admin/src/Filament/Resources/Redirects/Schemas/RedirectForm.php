<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Redirects\Schemas;

use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Filament\Components\Forms\LanguageSelect;
use Capell\Admin\Filament\Components\Forms\SiteSelect;
use Capell\Admin\Filament\Components\Forms\StatusToggle;
use Capell\Admin\Filament\Contracts\FormConfigurator;
use Capell\Core\Actions\Redirects\ValidateRedirectAction;
use Capell\Core\Enums\RedirectStatusCodeEnum;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class RedirectForm implements FormConfigurator
{
    public static function configure(Schema $schema, ?ConfiguratorContextData $context = null): Schema
    {
        return $schema->components(static::getFormSchema($schema))
            ->columns();
    }

    /** @return array<int, mixed> */
    protected static function getFormSchema(Schema $schema): array
    {
        return [
            Section::make(__('capell-admin::form.redirect_scope'))
                ->description(__('capell-admin::generic.redirect_scope_description'))
                ->columns()
                ->columnSpanFull()
                ->schema([
                    SiteSelect::make('site_id')
                        ->helperText(__('capell-admin::generic.redirect_site_info'))
                        ->reactive()
                        ->required()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('language_id', null);
                        }),

                    LanguageSelect::make('language_id')
                        ->helperText(__('capell-admin::generic.redirect_language_info'))
                        ->withRelationship()
                        ->modifyRelationQueryUsing(
                            fn (Builder $query, Get $get): Builder => $query->when(
                                $get('site_id'),
                                fn (Builder $query, int $siteId): Builder => $query->whereHas(
                                    'sites',
                                    fn (BuilderContract $query): BuilderContract => $query->where('sites.id', $siteId),
                                ),
                            ),
                        )
                        ->rules([
                            fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                $siteId = $get('site_id');

                                if (blank($value) || blank($siteId)) {
                                    return;
                                }

                                $languageBelongsToSite = Site::query()
                                    ->whereKey((int) $siteId)
                                    ->whereHas(
                                        'languages',
                                        fn (Builder $query): Builder => $query->whereKey((int) $value),
                                    )
                                    ->exists();

                                if (! $languageBelongsToSite) {
                                    $fail(__('capell-admin::message.site_language_not_accessible'));
                                }
                            },
                        ])
                        ->disabled(fn (Get $get): bool => $get('site_id') === null || $get('site_id') === 0)
                        ->required()
                        ->reactive(),
                ]),

            Section::make(__('capell-admin::form.redirect_rule'))
                ->description(__('capell-admin::generic.redirect_rule_description'))
                ->columns()
                ->columnSpanFull()
                ->schema([
                    TextInput::make('url')
                        ->label(__('capell-admin::form.source_url'))
                        ->helperText(__('capell-admin::generic.redirect_source_url_info'))
                        ->required()
                        ->placeholder('/')
                        ->rules([
                            fn (): Closure => function (string $attribute, ?string $value, Closure $fail): void {
                                if ($value === null || $value === '') {
                                    return;
                                }

                                if (! str_starts_with($value, '/')) {
                                    $fail(__('capell-admin::message.redirect_source_must_start_with_slash'));
                                }
                            },
                        ])
                        ->unique(
                            table: PageUrl::class,
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                                ->withoutTrashed()
                                ->where('language_id', $get('language_id'))
                                ->where('site_id', $get('site_id')),
                        ),

                    TextInput::make('target_url')
                        ->label(__('capell-admin::form.target_url'))
                        ->helperText(__('capell-admin::generic.redirect_target_url_info'))
                        ->required()
                        ->placeholder(__('capell-admin::form.target_url_placeholder'))
                        ->rules([
                            fn (Get $get): Closure => function (string $attribute, ?string $value, Closure $fail) use ($get): void {
                                if ($value === null || $value === '' || $get('url') === null) {
                                    return;
                                }

                                if ($value === $get('url')) {
                                    $fail(__('capell-admin::message.redirect_self_redirect'));
                                }

                                $result = ValidateRedirectAction::run(
                                    sourceUrl: (string) $get('url'),
                                    targetUrl: $value,
                                    siteId: (int) $get('site_id'),
                                    languageId: (int) $get('language_id'),
                                    validateDuplicateSource: false,
                                );

                                foreach ($result['errors'] as $error) {
                                    $fail($error);
                                }
                            },
                        ]),
                ]),

            Section::make(__('capell-admin::form.redirect_status'))
                ->description(__('capell-admin::generic.redirect_status_description'))
                ->columns()
                ->columnSpanFull()
                ->schema([
                    Radio::make('status_code')
                        ->label(__('capell-admin::form.status_code'))
                        ->helperText(__('capell-admin::generic.redirect_status_code_info'))
                        ->options(RedirectStatusCodeEnum::class)
                        ->default(RedirectStatusCodeEnum::Permanent)
                        ->inline()
                        ->required(),

                    StatusToggle::make('status'),

                    Textarea::make('notes')
                        ->label(__('capell-admin::form.notes'))
                        ->helperText(__('capell-admin::generic.redirect_notes_info'))
                        ->rows(3)
                        ->columnSpanFull(),
                ]),

            Hidden::make('type')
                ->default(UrlTypeEnum::Redirect->value),

            Hidden::make('is_manual')
                ->default(true),
        ];
    }
}
