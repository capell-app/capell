<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

use Capell\Core\Data\UpgradeContext;

interface UpgradeStepContract
{
    /**
     * Stable machine-readable identifier for this step.
     */
    public function id(): string;

    /**
     * Short human-readable name shown in the upgrade list.
     */
    public function label(): string;

    /**
     * Composer package this step belongs to (e.g. 'capell-app/capell').
     */
    public function package(): string;

    /**
     * Ordering hint. Lower values run first.
     */
    public function priority(): int;

    /**
     * Ids of steps that must complete before this one.
     *
     * @return array<int, string>
     */
    public function dependsOn(): array;

    /**
     * Whether this step should execute given the provided context.
     */
    public function shouldRun(UpgradeContext $context): bool;

    /**
     * Execute the upgrade step. Return true on success, false on failure.
     *
     * Steps are wrapped in a database transaction by the runner, but DDL can
     * implicitly commit on some database engines. Keep schema changes in
     * migrations where possible, and make step side effects idempotent.
     */
    public function run(UpgradeContext $context): bool;

    /**
     * Attempt to roll back the effects of this step. Return true if
     * rollback completed or is not necessary, false if rollback failed.
     */
    public function rollback(UpgradeContext $context): bool;
}
