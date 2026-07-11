<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Tables\Columns;

use Capell\Admin\Filament\Concerns\HasDefaultBadgeColumn;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Override;

class BadgeableColumn extends \Awcodes\BadgeableColumn\Components\BadgeableColumn
{
    use HasDefaultBadgeColumn;

    #[Override]
    public function getSuffix(): string|Htmlable|null
    {
        $badges = $this->getSuffixBadges();

        if ($badges !== '' && $badges !== '0') {
            $html = '';

            if (! in_array($this->getSeparator(), [null, '', '0'], true)) {
                $html .= ' <span style="opacity: 0.375;">' . $this->getSeparator() . '</span>';
            }

            $html .= '<span style="display:inline-flex;gap:1.4rem;">' . $badges . '</span>';

            return new HtmlString($html);
        }

        return parent::getSuffix();
    }
}
