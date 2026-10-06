<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Capell\Admin\Filament\Components\Tables\Columns\BadgeableColumn;
use Capell\Core\Models\Contracts\Defaultable;
use Illuminate\Support\HtmlString;

/**
 * @mixin BadgeableColumn
 */
trait HasDefaultBadgeColumn
{
    protected bool $hasDefaultBadge = false;

    public function defaultBadge(bool $hasDefault = true): self
    {
        $this->hasDefaultBadge = $hasDefault;

        return $this;
    }

    protected function getDefaultBadge(): ?HtmlString
    {
        $record = $this->getRecord();

        if (! $this->hasDefaultBadge || ! $record instanceof Defaultable || ! $record->isDefault()) {
            return null;
        }

        return new HtmlString(view('capell-admin::components.tables.columns.badge.default-badge')->render());
    }
}
