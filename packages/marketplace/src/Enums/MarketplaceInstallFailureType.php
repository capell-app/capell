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
    case HealthCheckFailed = 'health_check_failed';
    case RollbackFailed = 'rollback_failed';
    /**
     * The code was restored but the database was not, because it cannot be.
     *
     * Distinct from RollbackFailed: the Composer rollback did complete. What
     * did not, and never could, is the schema — restoring composer.lock does
     * not un-run a migration.
     */
    case SchemaAheadOfCode = 'schema_ahead_of_code';
    case MigrationFailed = 'migration_failed';
    case DeploymentFailed = 'deployment_failed';
    case DeploymentUnavailable = 'deployment_unavailable';
    case CancelledAfterComposer = 'cancelled_after_composer';
    case QueueWorkerMissing = 'queue_worker_missing';
    case Unknown = 'unknown';
}
