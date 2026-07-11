<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\Actions;

use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Page;
use Closure;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Override;

class RevisionsHeaderAction extends Action
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (Pageable $record): string => __('capell-admin::button.revisions', [
            'count' => $this->revisionCount($record),
        ]))
            ->icon('heroicon-o-clock')
            ->url(fn (Pageable $record): string => AdminSurfaceLookup::resource(ResourceEnum::Page)::getUrl('history', ['record' => $record]))
            ->visible(fn (Pageable $record): bool => Route::has('filament.admin.resources.pages.history') && $this->revisionCount($record) > 0);
    }

    public static function getDefaultName(): ?string
    {
        return 'revisions';
    }

    /** @param  Pageable<Page>  $record */
    private function revisionCount(Pageable $record): int
    {
        $revisionAction = 'Capell\\PublishingStudio\\Actions\\ListPublishingRevisionsAction';

        if ($record instanceof Model && class_exists($revisionAction)) {
            return (int) $revisionAction::run($record)->count();
        }

        $handler = app()->bound('capell.workspace.page-draft-handler')
            ? resolve('capell.workspace.page-draft-handler')
            : null;

        $countDrafts = is_object($handler) && is_callable([$handler, 'countDrafts'])
            ? $handler->countDrafts(...)
            : null;

        if ($countDrafts instanceof Closure) {
            return (int) $countDrafts($record);
        }

        return 0;
    }
}
