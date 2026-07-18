<?php

declare(strict_types=1);

namespace Capell\Core\Support\Creator;

use Capell\Core\Contracts\ModelInterceptors\LayoutInterceptorInterface;
use Capell\Core\Enums\LayoutEnum;
use Capell\Core\Enums\LayoutGroupEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Layout;
use Illuminate\Support\Facades\Lang;

final class LayoutCreator
{
    public function setup(): void
    {
        foreach (LayoutEnum::cases() as $case) {
            $this->create($case);
        }
    }

    public function create(null|string|LayoutEnum $enum = null): Layout
    {
        if ($enum === null) {
            $enum = LayoutEnum::Default;
        }

        if (is_string($enum)) {
            $key = $enum;
            $group = LayoutGroupEnum::Default;
        } else {
            $key = $enum->value;
            $group = $enum->getGroup();
        }

        return CapellCore::createOrUpdateModel(
            Layout::class,
            $key,
            function (array $data) use ($group, $key): array {
                $admin = $data['admin'] ?? [];
                $meta = $data['meta'] ?? [];

                if ($key === LayoutEnum::System->value) {
                    $admin['system_page_layout'] ??= true;
                }

                $descriptionKey = 'capell::layout.' . ($data['key'] ?? $key) . '_description';

                if (Lang::has($descriptionKey)) {
                    $meta['description'] = __($descriptionKey);
                }

                return [
                    'key' => $data['key'] ?? $key,
                    'group' => $data['group'] ?? $group,
                    'default' => ($data['key'] ?? $key) === LayoutEnum::Default->value,
                    'name' => __('capell::layout.' . ($data['key'] ?? $key)),
                    'admin' => $admin,
                    'meta' => $meta,
                ];
            },
            LayoutInterceptorInterface::class,
        );
    }

    public function createDefaultLayout(): Layout
    {
        return $this->create(LayoutEnum::Default);
    }

    public function createHomeLayout(): Layout
    {
        return $this->create(LayoutEnum::Home);
    }

    public function createResultsLayout(): Layout
    {
        return $this->create(LayoutEnum::Results);
    }

    public function createSystemLayout(): Layout
    {
        return $this->create(LayoutEnum::System);
    }
}
