<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum ExtensionContributionType: string
{
    case AdminPage = 'admin-page';
    case AdminResource = 'admin-resource';
    case AdminActionExtender = 'admin-action-extender';
    case Section = 'section';
    case PageType = 'page-type';
    case DashboardFilamentWidget = 'dashboard-widget';
    case OverviewStat = 'overview-stat';
    case SchemaExtender = 'schema-extender';
    case Configurator = 'configurator';
    case Model = 'model';
    case Permission = 'permission';
    case Route = 'route';
    case Setting = 'setting';
    case PageVariation = 'page-variation';
    case FrontendComponent = 'frontend-component';
    case ContentWidget = 'content-widget';
    case RenderHook = 'render-hook';
    case PublicRenderData = 'public-render-data';
    case Asset = 'asset';
    case Migration = 'migration';
    case ScheduledJob = 'scheduled-job';
    case ConsoleCommand = 'console-command';
    case AgentCapability = 'agent-capability';
    case ContentGraph = 'content-graph';
    case HealthCheck = 'health-check';
    case WorkflowAttention = 'workflow-attention';
    case OutboundEvent = 'outbound-event';
    case BlueprintSubject = 'blueprint-subject';

    public function bucket(): string
    {
        return match ($this) {
            self::AdminPage,
            self::AdminResource,
            self::AdminActionExtender,
            self::DashboardFilamentWidget,
            self::OverviewStat,
            self::SchemaExtender,
            self::Configurator,
            self::Permission,
            self::WorkflowAttention => 'admin',
            self::Section,
            self::Route,
            self::PageVariation,
            self::FrontendComponent,
            self::ContentWidget,
            self::RenderHook,
            self::PublicRenderData,
            self::Asset => 'frontend',
            self::Migration => 'install',
            default => 'runtime',
        };
    }
}
