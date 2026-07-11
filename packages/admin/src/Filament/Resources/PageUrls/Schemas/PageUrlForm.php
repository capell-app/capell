<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\PageUrls\Schemas;

use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Filament\Components\Forms\LanguageSelect;
use Capell\Admin\Filament\Components\Forms\Page\UrlTypeRadio;
use Capell\Admin\Filament\Components\Forms\PageMorphToSelect;
use Capell\Admin\Filament\Components\Forms\SiteSelect;
use Capell\Admin\Filament\Components\Forms\StatusToggle;
use Capell\Admin\Filament\Contracts\FormConfigurator;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Exceptions\MissingMorphedModelException;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rules\Unique;

class PageUrlForm implements FormConfigurator
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
            Section::make(__('capell-admin::form.page_url_destination'))
                ->description(__('capell-admin::generic.page_url_destination_info'))
                ->columns()
                ->columnSpanFull()
                ->schema([
                    SiteSelect::make('site_id')
                        ->helperText(__('capell-admin::generic.page_url_site_info'))
                        ->reactive()
                        ->required()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('language_id', null);
                            $set('pageable_id', null);
                            $set('pageable_type', null);
                        }),

                    LanguageSelect::make('language_id')
                        ->helperText(__('capell-admin::generic.page_url_language_info'))
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
                        ->reactive()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('pageable_type', null);
                            $set('pageable_id', null);
                        }),

                    PageMorphToSelect::make()
                        ->columnSpanFull()
                        ->required()
                        ->disabled(fn (Get $get): bool => in_array($get('site_id'), [null, '', 0], true))
                        ->modifyKeySelectOptionsQueryUsing(
                            fn (Builder $query, Get $get): Builder => $query
                                ->whereHas(
                                    'translations',
                                    fn (BuilderContract $query): BuilderContract => $query->where('language_id', $get('language_id')),
                                ),
                        )
                        ->modifyKeySelectUsing(
                            fn (Select $select): Select => $select
                                ->helperText(__('capell-admin::generic.page_url_page_info'))
                                ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                                    if (in_array($state, [null, '', '0'], true)) {
                                        $set('url', null);
                                    }

                                    $url = PageUrl::query()->where([
                                        'site_id' => $get('site_id'),
                                        'language_id' => $get('language_id'),
                                        'pageable_type' => $get('pageable_type'),
                                        'pageable_id' => $state,
                                    ])
                                        ->value('url');

                                    $set('url', $url);
                                }),
                        ),
                ]),

            Section::make(__('capell-admin::form.page_url.label'))
                ->description(__('capell-admin::generic.page_url_info'))
                ->columnSpanFull()
                ->schema([
                    UrlTypeRadio::make('type')
                        ->hiddenLabel(),
                    TextInput::make('url')
                        ->label(__('capell-admin::form.url'))
                        ->required()
                        ->reactive()
                        ->validationAttribute(__('capell-admin::form.url'))
                        ->columnSpanFull()
                        ->placeholder('/')
                        ->rules([
                            fn (Get $get): Closure => function (string $attribute, ?string $value, Closure $fail) use ($get): void {
                                switch ($get('type')) {
                                    case 'redirect':
                                        if (! filter_var($value, FILTER_VALIDATE_URL)) {
                                            $fail(__('capell-admin::message.page_url_redirect_invalid'));
                                        }

                                        break;
                                    default:
                                        if (in_array(preg_match('/^\/[a-z0-9\-_\/.]*$/', $value ?? ''), [0, false], true)) {
                                            $fail(__('capell-admin::message.page_url_invalid'));
                                        }
                                }
                            },
                        ])
                        ->unique(
                            table: PageUrl::class,
                            ignoreRecord: $schema->getOperation() !== 'replicate',
                            modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                                ->withoutTrashed()
                                ->where('language_id', $get('language_id'))
                                ->where('site_id', $get('site_id')),
                            // ->where('page_id', $get('page_id'))
                        )
                        ->helperText(
                            function (Get $get, ?string $state): string|HtmlString {
                                if ($get('type') === 'redirect') {
                                    return __('capell-admin::generic.page_url_redirect_url_info');
                                }

                                $pageType = $get('pageable_type');
                                $pageId = $get('pageable_id');

                                if (blank($pageType) || blank($pageId)) {
                                    return __('capell-admin::generic.page_url_path_info');
                                }

                                /** @var class-string<Model>|null $model */
                                $model = Relation::getMorphedModel($pageType);

                                throw_if($model === null, MissingMorphedModelException::class, $pageType);

                                /** @var (Pageable<Model>&Model)|null $page */
                                $page = $model::query()->find($get('pageable_id'));

                                if ($page === null) {
                                    return __('capell-admin::generic.page_url_path_info');
                                }

                                $language = Language::query()->find($get('language_id'));

                                if (! $language instanceof Language) {
                                    return __('capell-admin::generic.page_url_path_info');
                                }

                                return new HtmlString($page->getParentUrl(
                                    language: $language,
                                    fullUrl: true,
                                ) . '<strong>' . $state . '</strong>');
                            },
                        ),
                ]),

            Section::make(__('capell-admin::form.publish_status'))
                ->description(__('capell-admin::generic.page_url_publish_info'))
                ->columnSpanFull()
                ->schema([
                    StatusToggle::make('status'),
                ]),
        ];
    }
}
