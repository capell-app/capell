<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\AvatarProviders;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

final class InlineSvgAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $safeInitials = htmlspecialchars(
            $this->initials($record),
            ENT_QUOTES | ENT_XML1,
            'UTF-8',
        );

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96" role="img" aria-label="{$safeInitials}">
  <rect width="96" height="96" rx="48" fill="#111827"/>
  <text x="48" y="56" text-anchor="middle" fill="#ffffff" font-family="Inter, Arial, sans-serif" font-size="34" font-weight="700">{$safeInitials}</text>
</svg>
SVG;

        return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
    }

    private function initials(Model|Authenticatable $record): string
    {
        $initials = str(Filament::getNameForDefaultAvatar($record))
            ->trim()
            ->explode(' ')
            ->filter(fn (string $segment): bool => $segment !== '')
            ->map(fn (string $segment): string => mb_substr($segment, 0, 1))
            ->take(2)
            ->join('');

        if ($initials === '') {
            return '?';
        }

        return $initials;
    }
}
