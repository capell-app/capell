<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Tables\Columns\Page;

use BackedEnum;
use Capell\Admin\Contracts\Pages\PageTableStatusResolver;
use Capell\Admin\Data\Pages\PageTableStatusData;
use Capell\Core\Models\Page;
use Filament\Tables\Columns\TextColumn;

class PagePublishStatusColumn extends TextColumn
{
    /** @var array<int|string, PageTableStatusData> */
    private array $statuses = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::table.publish_status'))
            ->badge()
            ->alignCenter()
            ->width(0)
            ->toggleable()
            ->getStateUsing(fn (Page $record): string => $this->status($record)->shortLabel)
            ->tooltip(fn (Page $record): ?string => $this->status($record)->tooltip)
            ->color(fn (Page $record): string => $this->status($record)->color)
            ->icon(fn (Page $record): BackedEnum|string|null => $this->status($record)->icon);
    }

    private function status(Page $page): PageTableStatusData
    {
        $key = $page->getKey() ?? spl_object_id($page);

        return $this->statuses[$key] ??= $this->resolver()->resolve($page);
    }

    private function resolver(): PageTableStatusResolver
    {
        return resolve(PageTableStatusResolver::class);
    }
}
