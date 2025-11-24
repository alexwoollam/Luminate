<?php

declare(strict_types=1);

namespace Luminate\Model;

final class ModelRegistrar
{
    public function __construct(private readonly Registry $registry)
    {
    }

    public function boot(): void
    {
        if (function_exists('add_action')) {
            add_action('init', [$this, 'registerAll']);

            return;
        }

        $this->registerAll();
    }

    public function registerAll(): void
    {
        foreach ($this->registry->all() as $model) {
            $model->register();
        }
    }
}
