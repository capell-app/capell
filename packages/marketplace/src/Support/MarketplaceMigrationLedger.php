<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Illuminate\Database\Migrations\Migrator;
use Throwable;

/**
 * What the migration repository had recorded at a moment in time.
 *
 * This exists to answer one question honestly: did this operation change the
 * database schema? An update rollback restores composer.json, composer.lock and
 * vendor/ — it does not un-run a migration. So "we put the site back" is only
 * true when nothing migrated, and the operator has to be told which of the two
 * happened rather than being handed the same sentence either way.
 *
 * Reading the repository is deliberate rather than parsing the `migrate`
 * command's output: the wording of "Nothing to migrate" is not a contract, and
 * a settings migration is logged in the same table as a schema one, so one
 * ledger covers both.
 */
final readonly class MarketplaceMigrationLedger
{
    /**
     * @param  list<string>  $applied
     * @param  bool  $readable  False when the repository could not be read at
     *                          all. A ledger that does not know what was there
     *                          before cannot claim nothing changed.
     */
    private function __construct(
        public array $applied,
        public bool $readable,
    ) {}

    public static function capture(): self
    {
        try {
            /** @var Migrator $migrator */
            $migrator = resolve('migrator');
            $repository = $migrator->getRepository();

            if (! $repository->repositoryExists()) {
                return new self([], true);
            }

            return new self(array_values(array_map(strval(...), $repository->getRan())), true);
        } catch (Throwable) {
            return new self([], false);
        }
    }

    /**
     * The migrations that were logged between the earlier ledger and this one.
     *
     * @return list<string>
     */
    public function appliedSince(self $earlier): array
    {
        if (! $this->readable || ! $earlier->readable) {
            return [];
        }

        return array_values(array_diff($this->applied, $earlier->applied));
    }

    /**
     * Whether the schema is known to have moved since the earlier ledger.
     *
     * An unreadable ledger on either side answers true, not false: the whole
     * point of this class is to stop the operator being told the site was fully
     * restored, and "we could not check" is not evidence that it was.
     */
    public function changedSince(self $earlier): bool
    {
        if (! $this->readable || ! $earlier->readable) {
            return true;
        }

        return $this->appliedSince($earlier) !== [];
    }
}
