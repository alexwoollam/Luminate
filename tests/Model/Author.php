<?php

declare(strict_types=1);

namespace Luminate\Tests\Model;

use Luminate\Model\Model;

final class Author extends Model
{
    public function key(): string
    {
        return 'author';
    }

    protected function labels(): array
    {
        return [
            'name' => 'Authors',
            'singular_name' => 'Author',
        ];
    }

    protected function fillable(): array
    {
        return [
            'name' => self::TYPE_STRING,
        ];
    }

    /**
     * @return array<int, Book>
     */
    public function books(): array
    {
        return $this->hasMany(Book::class, 'author_id');
    }
}
