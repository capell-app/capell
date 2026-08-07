<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Models\Contracts\Blueprintable;
use Capell\Core\Support\Blueprints\CoreBlueprintSubjects;
use Capell\Core\Support\BlueprintSubjectRegistry;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

/**
 * One model's claim to operator-editable blueprints.
 *
 * This is the contract a package implements to open its own model to Capell's
 * blueprint machinery — schema snapshots, drift alerts and interceptors all key
 * off the subject key, so a registered subject inherits them with no further
 * work. Built-in Page, Site and Theme subjects are ordinary descriptors built by
 * {@see CoreBlueprintSubjects}; core has no
 * privileged path.
 *
 * Hand one to `$this->surface()->blueprintSubject(...)` in
 * `bootInstalledPackage()`. Every field is validated at registration time by
 * {@see BlueprintSubjectRegistry::register()}, which throws
 * on a malformed key, a model that cannot carry a blueprint, a missing owner
 * package, an unrunnable seeder, or a key another package already took.
 *
 * The shape is pinned by a contract test because it crosses the package
 * boundary, and it is deliberately serialisation-safe: labels are eager strings,
 * never closures, because descriptors are stored in `CapellCoreManager` and may
 * cross a Livewire boundary, where a closure dehydrates to `{}` and crashes the
 * component.
 */
final class BlueprintSubjectDescriptorData extends Data
{
    /**
     * @param  string  $key  Stable subject key stored in `blueprints.type`. Lowercase
     *                       kebab-case, optionally dot-namespaced by the owning
     *                       package (e.g. `structured-content.collection`).
     * @param  string  $label  Operator-facing name, already translated.
     * @param  class-string<Model&Blueprintable>  $modelClass  The model the blueprint configures.
     * @param  string  $ownerPackage  Composer name of the contributing package, used to
     *                                attribute orphaned rows after an uninstall.
     * @param  list<BlueprintGroupEnum>  $groups  Blueprint groups this subject may use;
     *                                            an empty list means every group.
     * @param  class-string|null  $defaultSchemaSeeder  Action run once, on first create, to
     *                                                  seed a starting blueprint.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $modelClass,
        public readonly string $ownerPackage,
        public readonly array $groups = [],
        public readonly ?string $defaultSchemaSeeder = null,
    ) {}

    public function allowsGroup(BlueprintGroupEnum $group): bool
    {
        return $this->groups === [] || in_array($group, $this->groups, true);
    }
}
