<?php

declare(strict_types=1);

namespace Luminate\Tests\Model;

use Luminate\Model\Concerns\SoftDeletes;
use Luminate\Model\Model;
use Luminate\Model\Query\Builder;

final class Book extends Model
{
    use SoftDeletes;

    public function key(): string
    {
        return 'book';
    }

    protected function labels(): array
    {
        return [
            'name' => 'Books',
            'singular_name' => 'Book',
        ];
    }

    protected function fillable(): array
    {
        return [
            'book_isbn' => self::TYPE_STRING,
            'is_featured' => self::TYPE_BOOL,
            'author_id' => self::TYPE_INT,
        ];
    }

    public function author(): ?Author
    {
        return $this->belongsTo(Author::class, 'author_id');
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->whereMeta('is_featured', '1');
    }
}
