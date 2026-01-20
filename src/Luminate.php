<?php

declare(strict_types=1);

namespace Luminate;

use Luminate\Contracts\Model as ModelContract;
use Luminate\Model\Model;
use Luminate\Model\ModelRegistrar;

final class Luminate
{
    public static function boot(ModelContract ...$models): Kernel
    {
        $kernel = new Kernel();
        $wordpress = $kernel->wordpress();

        foreach ($models as $model) {
            if ($model instanceof Model) {
                $model->setWordPress($wordpress);
            }

            $kernel->models()->add($model);
        }

        (new ModelRegistrar($kernel->models()))->boot();

        return $kernel;
    }
}
