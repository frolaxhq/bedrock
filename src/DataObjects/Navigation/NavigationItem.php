<?php

declare(strict_types=1);

namespace Frolax\Bedrock\DataObjects\Navigation;

use Spatie\LaravelData\Data;

class NavigationItem extends Data
{
    public function __construct(
        public readonly string $module,
        public readonly string $key,
        public readonly string $kind,
        public readonly string $label,
        public readonly ?string $route = null,
        public readonly ?string $href = null,
        public readonly ?string $group = null,
        public readonly int $order = 0,
        public readonly ?string $permission = null,
        /** @var list<string> */
        public readonly array $panels = [],
        public readonly bool $active = false,
        public readonly ?string $parent = null,
        /** @var list<self> */
        public readonly array $children = [],
    ) {}

    /**
     * The shared navigation envelope consumed by the frontend runtime.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'module' => $this->module,
            'key' => $this->key,
            'kind' => $this->kind,
            'label' => $this->label,
            'route' => $this->route,
            'href' => $this->href,
            'group' => $this->group,
            'order' => $this->order,
            'permission' => $this->permission,
            'panels' => $this->panels,
            'active' => $this->active,
            'parent' => $this->parent,
            'children' => array_map(static fn (self $child): array => $child->toArray(), $this->children),
        ];
    }
}
