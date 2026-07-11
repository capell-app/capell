<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

enum MarketplaceInstallFailureStage: string
{
    case Preflight = 'preflight';
    case DeploymentHandoff = 'deployment_handoff';
    case Composer = 'composer';
    case PackageDiscovery = 'package_discovery';
    case Lifecycle = 'lifecycle';
    case Notification = 'notification';
    case Queue = 'queue';
}
