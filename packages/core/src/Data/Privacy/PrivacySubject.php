<?php

declare(strict_types=1);

namespace Capell\Core\Data\Privacy;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The person a privacy request is about: a stored record (a user, subscriber or
 * customer), an email address, or both. Packages that key data by account use
 * the model; packages that hold guest data (a comment or form entry) use the
 * email address.
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
}
