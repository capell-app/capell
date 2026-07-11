<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Imports;

use Capell\Admin\Filament\Components\Forms\LanguageSelect;
use Capell\Admin\Filament\Components\Forms\SiteSelect;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Actions\Redirects\ValidateRedirectAction;
use Capell\Core\Enums\RedirectStatusCodeEnum;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\Radio;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Override;

class RedirectImporter extends Importer
{
    protected static ?string $model = PageUrl::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('url')
                ->label(__('capell-admin::form.source_url'))
                ->requiredMapping()
                ->rules(['required'])
                ->guess(['source_url', 'source_path', 'from', 'source']),

            ImportColumn::make('target_url')
                ->label(__('capell-admin::form.target_url'))
                ->requiredMapping()
                ->rules(['required'])
                ->guess(['target_url', 'target_path', 'to', 'target', 'destination']),

            ImportColumn::make('status_code')
                ->label(__('capell-admin::form.status_code'))
                ->integer()
                ->ignoreBlankState()
                ->guess(['status_code', 'code', 'http_status']),

            ImportColumn::make('notes')
                ->label(__('capell-admin::form.notes'))
                ->ignoreBlankState()
                ->guess(['note', 'comment', 'comments']),
        ];
    }

    #[Override]
    public static function getCompletedNotificationBody(Import $import): string
    {
        return __('capell-admin::message.redirect_import_complete', [
            'imported' => number_format($import->successful_rows),
            'skipped' => number_format($import->getFailedRowsCount()),
        ]);
    }

    /**
     * @return array<Component>
     */
    #[Override]
    public static function getOptionsFormComponents(): array
    {
        return [
            SiteSelect::make('site_id')
                ->modifyQueryUsing(fn (Builder $query): Builder => SiteScope::applyForCurrentActor($query, 'id', denyWhenMissingActor: true))
                ->reactive()
                ->required(),

            LanguageSelect::make('language_id')
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
                ->disabled(fn (Get $get): bool => $get('site_id') === null || $get('site_id') === 0)
                ->required()
                ->reactive(),

            Radio::make('default_status_code')
                ->label(__('capell-admin::form.default_status_code'))
                ->options(RedirectStatusCodeEnum::class)
                ->default(RedirectStatusCodeEnum::Permanent)
                ->inline(),
        ];
    }

    #[Override]
    public static function getModel(): string
    {
        return PageUrl::class;
    }

    #[Override]
    public function resolveRecord(): ?PageUrl
    {
        return new PageUrl;
    }

    protected function beforeFill(): void
    {
        /** @var PageUrl $record */
        $record = $this->record;
        $siteId = (int) $this->options['site_id'];
        $languageId = (int) $this->options['language_id'];
        $site = Site::query()->find($siteId);

        if ($site === null || ! SiteScope::actorCanUseSite($this->resolveActor(), $site)) {
            throw ValidationException::withMessages([
                'site_id' => __('capell-admin::message.redirect_import_invalid_site'),
            ]);
        }

        if (! $site->languages()->whereKey($languageId)->exists()) {
            throw ValidationException::withMessages([
                'language_id' => __('capell-admin::message.redirect_import_invalid_language'),
            ]);
        }

        $record->type = UrlTypeEnum::Redirect;
        $record->is_manual = true;
        $record->site_id = $siteId;
        $record->language_id = $languageId;
        $record->status = true;

        if (! $record->status_code instanceof RedirectStatusCodeEnum) {
            $record->status_code = $this->resolveDefaultStatusCode();
        }
    }

    protected function beforeSave(): void
    {
        /** @var PageUrl $record */
        $record = $this->record;

        $result = ValidateRedirectAction::run(
            sourceUrl: $record->url,
            targetUrl: (string) $record->target_url,
            siteId: $record->site_id,
            languageId: $record->language_id,
            statusCode: $record->status_code->value,
        );

        if ($result['errors'] !== []) {
            throw ValidationException::withMessages([
                'target_url' => $result['errors'],
            ]);
        }
    }

    private function resolveActor(): ?Authenticatable
    {
        $actor = auth()->user();

        if ($actor !== null) {
            return $actor;
        }

        if (! $this->import->exists) {
            return null;
        }

        return $this->import->user;
    }

    private function resolveDefaultStatusCode(): RedirectStatusCodeEnum
    {
        $statusCode = $this->options['default_status_code'] ?? RedirectStatusCodeEnum::Permanent;

        if ($statusCode instanceof RedirectStatusCodeEnum) {
            return $statusCode;
        }

        return RedirectStatusCodeEnum::tryFrom((int) $statusCode) ?? RedirectStatusCodeEnum::Permanent;
    }
}
