<?php

declare(strict_types=1);

namespace Luminate\Model\Concerns;

trait SoftDeletes
{
    protected function usesSoftDeletes(): bool
    {
        return true;
    }

    protected function getDeletedAtColumn(): string
    {
        return 'deleted_at';
    }
}
