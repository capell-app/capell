<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Agent;

use Capell\Core\Models\Blueprint;
use Capell\Core\Support\BlueprintSubjectRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class CreateAgentBlueprintAction
{
    use AsFake;
    use AsObject;

    /** @param array<string, mixed> $data */
    public function handle(array $data): Blueprint
    {
        $validated = $this->validate($data);

        return DB::transaction(function () use ($validated): Blueprint {
            if (Blueprint::query()
                ->where('type', $validated['type'])
                ->where('key', $validated['key'])
                ->exists()) {
                throw ValidationException::withMessages([
                    'key' => __('capell-admin::agent.blueprint_key_taken'),
                ]);
            }

            return Blueprint::query()->create($validated);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{type: string, key: string, name: string, group: string|null, order: int, status: bool, default: bool}
     */
    private function validate(array $data): array
    {
        /** @var array{type: string, key: string, name: string, group?: string|null, order?: int, status?: bool, default?: bool} $validated */
        $validated = validator($data, [
            'type' => ['required', 'string'],
            'key' => ['required', 'string', 'regex:/\A[a-z0-9][a-z0-9._-]{0,126}\z/'],
            'name' => ['required', 'string', 'max:191'],
            'group' => ['sometimes', 'nullable', 'string', 'max:191'],
            'order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'status' => ['sometimes', 'boolean'],
            'default' => ['sometimes', 'boolean'],
        ])->validate();

        $type = $validated['type'];
        if (! resolve(BlueprintSubjectRegistry::class)->has($type)) {
            throw ValidationException::withMessages([
                'type' => __('capell-admin::agent.blueprint_type_invalid'),
            ]);
        }

        return [
            'type' => $type,
            'key' => $validated['key'],
            'name' => $validated['name'],
            'group' => $validated['group'] ?? null,
            'order' => (int) ($validated['order'] ?? 0),
            'status' => (bool) ($validated['status'] ?? true),
            'default' => (bool) ($validated['default'] ?? false),
        ];
    }
}
