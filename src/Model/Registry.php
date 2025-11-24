<?php

declare(strict_types=1);

namespace Luminate\Model;

use Luminate\Contracts\Model as ModelContract;

final class Registry
{
    /**
     * @var array<string, ModelContract>
     */
    private array $items = [];

    public function add(ModelContract $model): void
    {
        $this->items[$model->key()] = $model;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    public function get(string $key): ?ModelContract
    {
        return $this->items[$key] ?? null;
    }

    /**
     * @return array<string, ModelContract>
     */
    public function all(): array
    {
        return $this->items;
    }
}
