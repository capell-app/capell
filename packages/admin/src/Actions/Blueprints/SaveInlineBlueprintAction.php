<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Blueprints;

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class SaveInlineBlueprintAction
{
    use AsFake;
    use AsObject;

    /** @param array<string, mixed> $data */
    public function handle(?Blueprint $blueprint, array $data): Blueprint
    {
        Gate::authorize($blueprint instanceof Blueprint ? 'update' : 'create', $blueprint ?? Blueprint::class);
        unset($data['creation_mode']);

        if (! $blueprint instanceof Blueprint) {
            if (auth()->user()?->can('manageRestrictions', Page::class) !== true) {
                unset($data['admin']['role_restrictions']);
            }

            return Blueprint::query()->create($data);
        }

        unset($data['name'], $data['key'], $data['type']);
        foreach (['admin', 'meta'] as $attribute) {
            $existing = is_array($blueprint->{$attribute}) ? $blueprint->{$attribute} : [];
            $submitted = is_array($data[$attribute] ?? null) ? $data[$attribute] : [];
            if ($submitted !== []) {
                $data[$attribute] = array_replace_recursive($existing, $submitted);
            }
        }

        return UpdateBlueprintAction::run($blueprint, $data);
    }
}
