<?php

declare(strict_types=1);

return [
    'not_enabled' => 'Package [:package] requires [:requirement], which is not installed/enabled or is blocked by another unmet requirement.',
    'source_missing' => 'Package [:package] requires [:requirement], whose source is unavailable. Make the requirement available through Composer, install and enable it, then retry the upgrade.',
    'manual_required' => 'Package [:package] requires [:requirement], which is not enabled. Automatic repair requires a non-interactive upgrade and an available first-party Capell package with a healthy lifecycle and runtime entitlement. Install or enable the requirement, repair any lifecycle or entitlement failure, then retry.',
    'circular' => 'Package [:package] has a circular dependency on requirement [:requirement]. Repair the manifest requirements before upgrading.',
    'upgrade_failed' => 'Could not install or enable requirement [:requirement] for package [:package]: :reason',
    'upgrade_repaired' => 'Installed and enabled requirement [:requirement] for package [:package].',
    'upgrade_dry_run' => 'Package [:package] requires [:requirement], which would be installed or enabled during a non-interactive upgrade.',
    'runtime_skipped' => 'Skipping installed runtime for package [:package]: required package [:requirement] is not installed/enabled or its runtime is blocked by another requirement.',
    'diagnostic_failed' => 'Installed runtime is blocked by unmet package requirements: :requirements.',
    'diagnostic_remediation' => 'Install and enable each named requirement, or run php artisan capell:upgrade --force --no-interaction to reconcile available first-party requirements. Retry in a fresh application and reload retained workers.',
];
