<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Controllers;

use Illuminate\Http\Response;

final class AdminAvatarController
{
    public function __invoke(string $initials): Response
    {
        $safeInitials = htmlspecialchars(
            mb_substr($initials, 0, 2),
            ENT_QUOTES | ENT_XML1,
            'UTF-8',
        );

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96" role="img" aria-label="{$safeInitials}">
  <rect width="96" height="96" rx="48" fill="#111827"/>
  <text x="48" y="56" text-anchor="middle" fill="#ffffff" font-family="Inter, Arial, sans-serif" font-size="34" font-weight="700">{$safeInitials}</text>
</svg>
SVG;

        return response($svg, 200)
            ->header('Content-Type', 'image/svg+xml')
            ->header('Cache-Control', 'private, max-age=3600');
    }
}
