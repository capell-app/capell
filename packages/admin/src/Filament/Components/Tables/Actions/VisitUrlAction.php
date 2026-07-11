<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Tables\Actions;

use Capell\Admin\Support\PageUrlPresenter;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\SiteDomain;
use Filament\Actions\Action;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Override;

class VisitUrlAction extends Action
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::button.visit_page'))
            ->icon('heroicon-o-eye')
            ->color('info')
            ->tooltip(function (): string {
                $label = $this->getLabel();

                return $label instanceof Htmlable ? $label->toHtml() : (string) $label;
            })
            ->visible(fn (Model $record): bool => PageUrlPresenter::fullUrl($this->pageUrl($record)) !== null)
            ->url(fn (Model $record): ?string => PageUrlPresenter::fullUrl($this->pageUrl($record)), shouldOpenInNewTab: true);
    }

    public static function getDefaultName(): string
    {
        return 'visit-page';
    }

    private function pageUrl(Model $record): ?PageUrl
    {
        $pageUrl = $record->getRelationValue('pageUrl');

        if (! $pageUrl instanceof PageUrl || ! $pageUrl->exists) {
            return null;
        }

        if (! $pageUrl->relationLoaded('siteDomain')) {
            $pageUrl->loadMissing('siteDomain');
        }

        return $pageUrl->getRelation('siteDomain') instanceof SiteDomain ? $pageUrl : null;
    }
}
