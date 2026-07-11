<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

enum MarketplaceInstallFailureType: string
{
    case PhpBinary = 'php_binary';
    case ComposerAuth = 'composer_auth';
    case ComposerConstraint = 'composer_constraint';
    case Network = 'network';
    case Timeout = 'timeout';
    case PackageNotDiscovered = 'package_not_discovered';
    case LifecycleException = 'lifecycle_exception';
    case DeploymentFailed = 'deployment_failed';
    case DeploymentUnavailable = 'deployment_unavailable';
    case CancelledAfterComposer = 'cancelled_after_composer';
    case Unknown = 'unknown';
}
