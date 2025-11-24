<?php

declare(strict_types=1);

namespace Luminate\Model\Column;

use Closure;

final class CallbackColumn implements Column
{
    /**
     * @param Closure(int):string $renderer
     */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly Closure $renderer
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function render(int $postId): string
    {
        return (string) ($this->renderer)($postId);
    }
}
