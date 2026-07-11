<?php

declare(strict_types=1);

namespace Capell\Admin\Data\HeaderNavigation;

use Spatie\LaravelData\Data;

final class HeaderNavigationPageNodeData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly int $siteId,
        public readonly ?int $parentId,
        public readonly string $name,
        public readonly ?string $typeIcon,
        public readonly string $editUrl,
        public readonly ?string $publicUrl,
        public readonly bool $hasChildren,
    ) {}

    /**
     * @return array{
     *     id: int,
     *     site_id: int,
     *     parent_id: ?int,
     *     name: string,
     *     type_icon: ?string,
     *     edit_url: string,
     *     public_url: ?string,
     *     has_children: bool
     * }
     */
    public function toRecord(): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->siteId,
            'parent_id' => $this->parentId,
            'name' => $this->name,
            'type_icon' => $this->typeIcon,
            'edit_url' => $this->editUrl,
            'public_url' => $this->publicUrl,
            'has_children' => $this->hasChildren,
        ];
    }
}
