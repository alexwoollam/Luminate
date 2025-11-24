<?php

declare(strict_types=1);

namespace Luminate\Support;

use Luminate\Kernel;

abstract class ServiceProvider
{
    public function __construct(protected readonly Kernel $app)
    {
    }

    public function register(): void
    {
    }

    public function boot(): void
    {
    }
}
