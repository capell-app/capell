<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Tables\Columns;

use Capell\Admin\Filament\Concerns\HasDefaultBadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Override;

class BadgeableColumn extends TextColumn
{
    use HasDefaultBadgeColumn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Filament skips affix formatting unless a formatter or affix is configured.
        $this->formatStateUsing(static fn (mixed $state): mixed => $state);
    }

    #[Override]
    public function getSuffix(mixed $state = null, ?Model $relatedRecord = null): string|Htmlable|null
    {
        // Preserve zero-argument callback evaluation on every supported Filament version.
        $suffix = parent::getSuffix(...func_get_args());
        $badge = $this->getDefaultBadge();

        if (! $badge instanceof HtmlString) {
            return $suffix;
        }

        $suffixHtml = $suffix instanceof Htmlable ? $suffix->toHtml() : e($suffix ?? '');

        return new HtmlString($suffixHtml . ' <span style="opacity:0.375;">&mdash;</span> <span style="display:inline-flex;gap:1.4rem;">' . $badge->toHtml() . '</span>');
    }
}
