<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Names one model that has just left public view, such as a page whose scheduled expiry passed. */
final readonly class PublicModelWithdrawalData
{
    public function __construct(
        public string $morphClass,
        public int $modelKey,
    ) {
        throw_if($this->morphClass === '', InvalidArgumentException::class, 'A public model withdrawal needs the model type.');
    }

    public static function forModel(Model $model): self
    {
        $key = $model->getKey();

        throw_unless(is_int($key) || (is_string($key) && ctype_digit($key)), InvalidArgumentException::class, 'A public model withdrawal needs an integer model key.');

        return new self($model->getMorphClass(), (int) $key);
    }
}
