<?php

declare(strict_types=1);

namespace Luminate\Contracts;

interface Model
{
    public function key(): string;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array;

    public function register(): void;
}
