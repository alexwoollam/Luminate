<?php

declare(strict_types=1);

namespace Luminate\Model\Column;

interface Column
{
    public function key(): string;

    public function label(): string;

    public function render(int $postId): string;
}
