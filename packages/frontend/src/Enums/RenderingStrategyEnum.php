<?php

declare(strict_types=1);

namespace Capell\Frontend\Enums;

enum RenderingStrategyEnum: string
{
    /**
     * Plain Blade rendering (default, fastest).
     * Use for static pages, blog posts, marketing content.
     */
    case BladeOnly = 'blade';

    /**
     * Blade with Livewire islands for specific interactive components.
     * Use for pages with isolated interactive features (form-builder, filters, toggles).
     * More efficient than full-page Livewire (no AJAX per interaction).
     */
    case BladeWithIslands = 'blade-islands';

    /**
     * Full Livewire component (legacy, for complex interactive pages).
     * Use only when entire page needs reactivity (dashboards, real-time content).
     * Higher bandwidth and server overhead per interaction.
     */
    case FullLivewire = 'livewire';

    public function isBladeOnly(): bool
    {
        return $this === self::BladeOnly;
    }

    public function supportsIslands(): bool
    {
        return $this !== self::FullLivewire;
    }

    public function requiresLivewire(): bool
    {
        return $this !== self::BladeOnly;
    }
}
