<?php

declare(strict_types=1);

namespace Luminate;

use Closure;
use Luminate\Model\Registry;
use Luminate\Support\ServiceProvider;
use RuntimeException;

final class Kernel
{
    /**
     * @var array<string, array{factory: Closure, shared: bool}>
     */
    private array $bindings = [];

    /**
     * @var array<string, object>
     */
    private array $instances = [];

    /**
     * @var list<ServiceProvider>
     */
    private array $providers = [];

    public function __construct()
    {
        $this->singleton(Registry::class, static fn (): Registry => new Registry());
    }

    public function bind(string $abstract, Closure $factory, bool $shared = false): void
    {
        $this->bindings[$abstract] = [
            'factory' => $factory,
            'shared' => $shared,
        ];
    }

    public function singleton(string $abstract, Closure $factory): void
    {
        $this->bind($abstract, $factory, true);
    }

    public function instance(string $abstract, object $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    public function make(string $abstract): object
    {
        if (array_key_exists($abstract, $this->instances)) {
            return $this->instances[$abstract];
        }

        if (!array_key_exists($abstract, $this->bindings)) {
            throw new RuntimeException(sprintf('Nothing bound to [%s].', $abstract));
        }

        $object = ($this->bindings[$abstract]['factory'])($this);

        if ($this->bindings[$abstract]['shared']) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    public function register(ServiceProvider $provider): void
    {
        $this->providers[] = $provider;
        $provider->register();
    }

    public function boot(): void
    {
        foreach ($this->providers as $provider) {
            $provider->boot();
        }
    }

    public function models(): Registry
    {
        return $this->make(Registry::class);
    }
}
