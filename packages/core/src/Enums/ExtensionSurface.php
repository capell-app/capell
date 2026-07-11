<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum ExtensionSurface: string
{
    case Content = 'content';
    case Admin = 'admin';
    case Frontend = 'frontend';
    case Workflow = 'workflow';
    case Delivery = 'delivery';
    case Operations = 'operations';
    case Integrations = 'integrations';
    case Marketplace = 'marketplace';
    case Console = 'console';
    case Shared = 'shared';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(
            static fn (self $surface): string => $surface->value,
            self::cases(),
        );
    }

    public function label(): string
    {
        return match ($this) {
            self::Content => 'Content',
            self::Admin => 'Admin',
            self::Frontend => 'Frontend',
            self::Workflow => 'Workflow',
            self::Delivery => 'Delivery',
            self::Operations => 'Operations',
            self::Integrations => 'Integrations',
            self::Marketplace => 'Marketplace',
            self::Console => 'Console',
            self::Shared => 'Shared',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Content => 'Content models, page types, blueprints, fields, and structured editorial data.',
            self::Admin => 'Filament resources, pages, widgets, settings, permissions, and editor workspace tools.',
            self::Frontend => 'Public rendering, widgets, render hooks, themes, routes, and frontend assets.',
            self::Workflow => 'Publishing, approvals, reviews, notifications, and lifecycle automation.',
            self::Delivery => 'Caching, static export, asset optimization, invalidation, and response delivery.',
            self::Operations => 'Diagnostics, health checks, scheduled jobs, upgrade tooling, and maintenance commands.',
            self::Integrations => 'External services, webhooks, provider adapters, imports, and exports.',
            self::Marketplace => 'Listing metadata, commercial terms, screenshots, support, trust, and install authorization.',
            self::Console => 'Console commands and command-line install, setup, diagnostics, or maintenance workflows.',
            self::Shared => 'Runtime-neutral package code, metadata, settings, contracts, and services shared across surfaces.',
        };
    }
}
