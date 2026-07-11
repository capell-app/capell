<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Awcodes\BadgeableColumn\Components\Badge;
use Awcodes\BadgeableColumn\Components\BadgeableColumn;
use Capell\Core\Models\Contracts\Defaultable;
use Illuminate\Support\Str;

/**
 * @mixin BadgeableColumn
 */
trait HasDefaultBadgeColumn
{
    protected bool $hasDefaultBadge = false;

    public function getSuffixBadges(): string
    {
        $suffixBadges = parent::getSuffixBadges();

        if ($this->hasDefaultBadge) {
            $defaultBadge = Badge::make('default')
                ->label(__('capell-admin::generic.default'))
                ->color('info')
                ->visible(fn (Defaultable $record): bool => $record->isDefault());

            $badgeHtml = $defaultBadge
                ->column($this)
                ->isPill($this->shouldBePills())
                ->render();

            $suffixBadges .= Str::of($badgeHtml->render())
                ->replace('<!-- __BLOCK__ --> ', '')
                ->replace('<!-- __ENDBLOCK__ -->', '')
                ->replace('<!--[if BLOCK]><![endif]-->', '')
                ->replace('<!--[if ENDBLOCK]><![endif]-->', '')
                ->replace('/n', '')
                ->trim();
        }

        return $suffixBadges;
    }

    public function defaultBadge(bool $hasDefault = true): self
    {
        $this->hasDefaultBadge = $hasDefault;

        return $this;
    }
}
