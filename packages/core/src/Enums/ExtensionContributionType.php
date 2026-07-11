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
    case Asset = 'asset';
    case Migration = 'migration';
    case ScheduledJob = 'scheduled-job';
    case ConsoleCommand = 'console-command';
    case AgentCapability = 'agent-capability';
    case ContentGraph = 'content-graph';
    case HealthCheck = 'health-check';
    case WorkflowAttention = 'workflow-attention';
}
