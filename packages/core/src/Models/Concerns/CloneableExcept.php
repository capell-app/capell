<?php

declare(strict_types=1);

namespace Capell\Core\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * @mixin Model
 */
trait CloneableExcept
{
    /**
     * @param  list<string>  $except
     * @param  array<string, mixed>|null  $attr
     */
    public function duplicateExcept(array $except, ?array $attr = null): static
    {
        $exempt = array_values(array_filter(array_merge(
            $this->getCloneExemptAttributes(),
            $except,
            $this->clone_exempt_attributes,
        ), is_string(...)));

        $original = $this->clone_exempt_attributes;

        $this->clone_exempt_attributes = $exempt;

        $clone = $this->duplicate($attr);

        $this->clone_exempt_attributes = $original;

        throw_if($clone === null, RuntimeException::class, 'Model could not be duplicated.');

        return $clone;
    }
}
