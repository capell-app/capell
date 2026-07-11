<?php

declare(strict_types=1);

namespace Capell\Tests\Fixtures\Models;

class InMemoryUserModel
{
    /** @var list<self> */
    public static array $records = [];

    public bool $intercepted = false;

    public bool $createdIntercepted = false;

    /** @param array<string, mixed> $attributes */
    public function __construct(public array $attributes = []) {}

    public static function query(): InMemoryUserModelQuery
    {
        return new InMemoryUserModelQuery;
    }

    public static function resetRecords(): void
    {
        self::$records = [];
    }

    /** @param array<string, mixed> $attributes */
    public function fill(array $attributes): self
    {
        $this->attributes = array_merge($this->attributes, $attributes);

        return $this;
    }

    public function save(): void {}
}
