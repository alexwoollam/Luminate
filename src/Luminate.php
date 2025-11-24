<?php

declare(strict_types=1);

namespace Luminate;

use Luminate\Contracts\Model as ModelContract;
use Luminate\Model\ModelRegistrar;

final class Luminate
{
    public static function boot(ModelContract ...$models): Kernel
    {
        $kernel = new Kernel();

        foreach ($models as $model) {
            $kernel->models()->add($model);
        }

        (new ModelRegistrar($kernel->models()))->boot();

        return $kernel;
    }
}
