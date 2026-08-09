<?php

declare(strict_types=1);

namespace Capell\Core\Models\Casts;

use Capell\Core\Data\BlueprintSubjectDescriptorData;
use Capell\Core\Data\PageTypeData;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Exceptions\UnknownBlueprintSubjectException;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Blueprint;
use Capell\Core\Support\BlueprintSubjectRegistry;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts `blueprints.type` between its stored subject key and a resolved
 * {@see PageTypeData}.
 *
 * The two directions answer to different risks, so they behave differently on an
 * unknown key:
 *
 * - **Writing** fails closed. Storing a subject no package registered would
 *   create a row nothing can resolve, so `set()` throws rather than persisting
 *   it. Anything that is not a subject key at all — an int, an array, null —
 *   throws too; silently passing it through is how a malformed type reaches the
 *   column.
 * - **Reading** degrades. Uninstalling a package leaves its blueprint rows
 *   behind by design, and an admin list that crashes on them cannot be used to
 *   clean them up. `get()` returns an unavailable-subject
 *   {@see PageTypeData} instead, which listing surfaces render as such.
 *
 * @implements CastsAttributes<PageTypeData, BlueprintSubjectEnum|PageTypeData|string>
 */
class BlueprintSubjectDataCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): PageTypeData
    {
        $type = (string) $value;

        // Reserved internal types are not subjects, so they are resolved by
        // whichever package registered them as a page type (navigation, today).
        // If that package is gone the row is orphaned like any other.
        if (Blueprint::isReservedInternalType($type)) {
            return CapellCore::hasPageType($type)
                ? CapellCore::getPageType($type)
                : PageTypeData::unavailableSubject($type);
        }

        $subject = resolve(BlueprintSubjectRegistry::class)->descriptorOrNull($type);

        if (! $subject instanceof BlueprintSubjectDescriptorData) {
            return PageTypeData::unavailableSubject($type);
        }

        return new PageTypeData(
            name: $subject->key,
            model: $subject->modelClass,
            label: $subject->label,
        );
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if ($value instanceof PageTypeData) {
            return $value->name;
        }

        if ($value instanceof BlueprintSubjectEnum) {
            return $value->getKey();
        }

        if (! is_string($value)) {
            throw UnknownBlueprintSubjectException::forNonSubjectValue($key, get_debug_type($value));
        }

        if (Blueprint::isReservedInternalType($value)) {
            return $value;
        }

        return resolve(BlueprintSubjectRegistry::class)->descriptor($value)->key;
    }
}
