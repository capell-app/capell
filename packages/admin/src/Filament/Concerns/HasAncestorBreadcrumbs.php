<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Models\Page;
use Filament\Pages\Page as FilamentPage;
use Illuminate\Contracts\Support\Htmlable;

/**
 * @mixin FilamentPage
 */
trait HasAncestorBreadcrumbs
{
    public function getBreadcrumbs(): array
    {
        $resource = static::getResource();

        $breadcrumbs = [
            $resource::getUrl() => $resource::getBreadcrumb(),
        ];

        $this->record->loadMissing('ancestors.blueprint');

        if ($this->record->ancestors && $this->record->ancestors->isNotEmpty()) {
            foreach ($this->record->ancestors as $ancestor) {
                $url = match ($ancestor::class) {
                    Page::class => GetEditPageResourceUrlAction::run($ancestor),
                    default => $resource::getUrl('edit', ['record' => $ancestor]),
                };

                if ($url !== null && $url !== '') {
                    $breadcrumbs[$url] = $ancestor->name;
                }
            }
        }

        if ($resource::hasRecordTitle()) {
            $recordTitle = $this->getRecordTitle();
            $recordTitle = $recordTitle instanceof Htmlable ? $recordTitle->toHtml() : $recordTitle;

            if ($resource::hasPage('view') && $resource::canView($this->record)) {
                $breadcrumbs[$resource::getUrl('view', ['record' => $this->record])] = $recordTitle;
            } elseif ($resource::hasPage('edit') && $resource::canEdit($this->record)) {
                $breadcrumbs[$resource::getUrl('edit', ['record' => $this->record])] = $recordTitle;
            } else {
                $breadcrumbs[] = $recordTitle;
            }
        }

        $breadcrumbs[] = $this->getBreadcrumb();

        return $breadcrumbs;
    }
}
