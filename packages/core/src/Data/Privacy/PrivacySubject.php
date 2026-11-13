<?php

declare(strict_types=1);

namespace Capell\Core\Data\Privacy;

use Capell\Core\Data\Database\SqlFragment;
use Capell\Core\Support\Privacy\PrivacyIdentifierHasher;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use LogicException;

/**
 * The person a privacy request is about: a stored record (a user, subscriber or
 * customer), an email address, or both. Packages that key data by account use
 * the model; packages that hold guest data (a comment or form entry) use the
 * email address.
 *
 * Building a subject does not check that the email address belongs to the
 * record, or that anyone may act on it: the caller verifies the person's
 * identity and authorises the request first.
 *
 * A subject cannot be serialised, so it never lands in a queue payload or the
 * failed-jobs table with the person's email address and record attributes. A
 * queued job carries the privacy request id and builds the subject when it runs.
 */
final readonly class PrivacySubject
{
    public ?string $email;

    private function __construct(public ?Model $model, ?string $email)
    {
        $email = $email === null ? null : mb_strtolower(trim($email));

        throw_if(! $model instanceof Model && $email === null, InvalidArgumentException::class, 'A privacy subject needs a record or an email address.');
        throw_if($model instanceof Model && ! $model->exists, InvalidArgumentException::class, 'A privacy subject record must be stored.');
        throw_if($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false, InvalidArgumentException::class, 'A privacy subject email address must be valid.');

        $this->email = $email;
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('A privacy subject is not serialised: queue the privacy request id and build the subject when the job runs.');
    }

    /**
     * @param  string|null  $email  an address the caller has verified as the person's; not checked against the record
     */
    public static function forModel(Model $model, ?string $email = null): self
    {
        return new self($model, $email);
    }

    public static function forEmail(string $email): self
    {
        return new self(null, $email);
    }

    /**
     * Whether the subject is this kind of record, so an exporter can support
     * only the models it stores data for.
     *
     * @param  class-string<Model>  $class
     */
    public function isA(string $class): bool
    {
        return $this->model instanceof $class;
    }

    /**
     * The morph type and key of the subject record, or null for an email-only
     * subject.
     *
     * @return array{type: string, id: string}|null
     */
    public function reference(): ?array
    {
        if (! $this->model instanceof Model) {
            return null;
        }

        return ['type' => $this->model->getMorphClass(), 'id' => (string) $this->model->getKey()];
    }

    /**
     * The keyed hash a package stores the subject's email address under
     * (PrivacyIdentifierHasher::hash()), or null for a subject without one.
     */
    public function emailHash(): ?string
    {
        return $this->email === null ? null : PrivacyIdentifierHasher::hash($this->email);
    }

    /**
     * Narrows a query to rows whose hash column holds the subject's email address
     * hashed with PrivacyIdentifierHasher::hash(), under the current key or any
     * earlier one (including app.key after a dedicated key is set). Only the hashes
     * reach the query, so the address stays out of query logs. A subject without
     * an email address matches nothing. The query is changed in place, with its
     * existing conditions grouped first so an earlier orWhere cannot widen the
     * match to other people's rows.
     *
     * @param  QueryBuilder|EloquentBuilder<covariant Model>  $query
     */
    public function whereEmailHash(QueryBuilder|EloquentBuilder $query, string $column): void
    {
        $query = $this->groupExistingConditions($query);

        if ($this->email === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn($column, PrivacyIdentifierHasher::candidates($this->email));
    }

    /**
     * Narrows a query to rows whose email column matches the subject's address,
     * ignoring case on every database (SQLite and PostgreSQL compare case
     * sensitively by default). The address is a query binding, so it can reach
     * query logs: a package that looks people up often stores a keyed hash and
     * uses whereEmailHash() instead. A subject without an email address matches
     * nothing. The query is changed in place, with its existing conditions
     * grouped first so an earlier orWhere cannot widen the match.
     *
     * @param  QueryBuilder|EloquentBuilder<covariant Model>  $query
     */
    public function whereEmail(QueryBuilder|EloquentBuilder $query, string $column): void
    {
        $base = $this->groupExistingConditions($query);

        if ($this->email === null) {
            $base->whereRaw('1 = 0');

            return;
        }

        // The grammar quotes the column name; the address is a binding.
        new SqlFragment('LOWER(' . $base->getGrammar()->wrap($column) . ') = ?', [$this->email])->applyWhere($base);
    }

    /**
     * Moves the query's existing where conditions into one nested group, so a
     * condition added after it is ANDed with the whole group: "a OR b" becomes
     * "(a OR b) AND subject", not "a OR (b AND subject)". Returns the base query
     * the subject condition is added to.
     *
     * @param  QueryBuilder|EloquentBuilder<covariant Model>  $query
     */
    private function groupExistingConditions(QueryBuilder|EloquentBuilder $query): QueryBuilder
    {
        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;

        if ($base->wheres === []) {
            return $base;
        }

        $group = $base->forNestedWhere();
        $group->wheres = $base->wheres;
        $group->bindings['where'] = $base->bindings['where'];
        $base->wheres = [];
        $base->bindings['where'] = [];
        $base->addNestedWhereQuery($group);

        return $base;
    }
}
