<?php

declare(strict_types=1);

namespace Luminate\Tests\Model;

use Luminate\Model\Concerns\SoftDeletes;
use Luminate\Model\Model;
use Luminate\Model\Query\Builder;
use Luminate\Tests\Support\WordPressStub;
use Luminate\Tests\TestCase;

final class ModelTest extends TestCase
{
    public function testCreatePersistsPostAndMeta(): void
    {
        $metaStore = [];

        WordPressStub::fake('wp_insert_post', static fn (array $data, bool $error): int => 101);

        WordPressStub::fake('update_post_meta', function (int $postId, string $key, mixed $value) use (&$metaStore): void {
            $metaStore[$postId][$key] = $value;
        });

        WordPressStub::fake('get_post_meta', function (int $postId, string $key) use (&$metaStore): mixed {
            return $metaStore[$postId][$key] ?? '';
        });

        WordPressStub::fake('get_post', static fn (int $postId): object => (object) [
            'ID' => $postId,
            'post_title' => 'My First Book',
            'post_status' => 'publish',
            'post_name' => 'my-first-book',
        ]);

        WordPressStub::fake('get_posts', static fn (): array => []);

        $book = Book::create([
            'title' => 'My First Book',
            'slug' => 'my-first-book',
            'status' => 'publish',
            'book_isbn' => '9780000000001',
            'is_featured' => true,
        ]);

        $this->assertSame(101, $book->id());
        $this->assertSame('9780000000001', $book->book_isbn);
        $this->assertTrue($book->is_featured);
        $this->assertSame('9780000000001', $metaStore[101]['book_isbn'] ?? null);
        $this->assertSame('1', $metaStore[101]['is_featured'] ?? null);
    }

    public function testSaveSkipsUnchangedAttributes(): void
    {
        $metaStore = [
            55 => [
                'book_isbn' => '9780000000005',
                'is_featured' => '1',
                'author_id' => '10',
            ],
        ];

        WordPressStub::fake('get_post_meta', function (int $postId, string $key) use (&$metaStore): mixed {
            return $metaStore[$postId][$key] ?? '';
        });

        $post = (object) [
            'ID' => 55,
            'post_title' => 'Existing Book',
            'post_status' => 'publish',
            'post_name' => 'existing-book',
        ];

        $book = (new Book())->newFromPost($post);

        $updatedFields = [];

        WordPressStub::fake('update_post_meta', function (int $postId, string $key, mixed $value) use (&$updatedFields): void {
            $updatedFields[] = $key;
        });

        $book->save();

        $this->assertSame('9780000000005', $book->book_isbn);
        $this->assertTrue($book->is_featured);
        $this->assertContains('updated_at', $updatedFields);
        $this->assertNotContains('book_isbn', $updatedFields);
    }

    public function testRelationshipsCanBeLoadedAndEagerLoaded(): void
    {
        $posts = [
            7 => (object) [
                'ID' => 7,
                'post_title' => 'Jane Doe',
                'post_status' => 'publish',
                'post_name' => 'jane-doe',
            ],
            5 => (object) [
                'ID' => 5,
                'post_title' => 'Linked Book',
                'post_status' => 'publish',
                'post_name' => 'linked-book',
            ],
        ];

        WordPressStub::fake('get_post', static fn (int $postId): ?object => $posts[$postId] ?? null);

        $metaStore = [
            7 => ['name' => 'Jane Doe'],
            5 => [
                'book_isbn' => '9780000000009',
                'is_featured' => '1',
                'author_id' => '7',
            ],
        ];

        WordPressStub::fake('get_post_meta', function (int $postId, string $key) use (&$metaStore): mixed {
            return $metaStore[$postId][$key] ?? '';
        });

        $capturedQuery = [];

        WordPressStub::fake('get_posts', function (array $args) use (&$capturedQuery, $posts): array {
            $capturedQuery = $args;

            return [$posts[5]];
        });

        $author = (new Author())->newFromPost($posts[7]);

        $books = $author->books();

        $this->assertCount(1, $books);
        $this->assertSame(7, $books[0]->author_id);
        $this->assertSame('author_id', $capturedQuery['meta_query'][0]['key'] ?? null);
        $this->assertSame('7', $capturedQuery['meta_query'][0]['value'] ?? null);

        $book = Book::query()->with('author')->first();

        $this->assertNotNull($book);
        $this->assertSame(5, $book->id());
        $this->assertNotNull($book->author);
        $this->assertSame(7, $book->author->id());
    }
    public function testQueryBuilderTransformsArgs(): void
    {
        $captured = [];

        WordPressStub::fake('get_posts', function (array $args) use (&$captured): array {
            $captured = $args;

            return [];
        });

        Book::query()
            ->whereStatus('draft')
            ->whereMeta('author_id', 7)
            ->orderBy('title', 'desc')
            ->limit(5)
            ->all(['s' => 'search term']);

        $this->assertSame('book', $captured['post_type'] ?? null);
        $this->assertSame('draft', $captured['post_status'] ?? null);
        $this->assertSame('title', $captured['orderby'] ?? null);
        $this->assertSame('DESC', $captured['order'] ?? null);
        $this->assertSame(5, $captured['numberposts'] ?? null);
        $this->assertSame('search term', $captured['s'] ?? null);
        $this->assertSame('author_id', $captured['meta_query'][0]['key'] ?? null);
        $this->assertSame(7, $captured['meta_query'][0]['value'] ?? null);
        $this->assertSame('NOT EXISTS', $captured['meta_query'][1]['compare'] ?? null);
    }

    public function testScopesAndCount(): void
    {
        $captured = [];

        WordPressStub::fake('get_posts', function (array $args) use (&$captured): array {
            $captured = $args;

            return [
                (object) ['ID' => 1, 'post_title' => 'Scoped'],
            ];
        });

        $books = Book::query()->featured()->all();

        $this->assertSame('is_featured', $captured['meta_query'][0]['key'] ?? null);
        $this->assertSame('1', $captured['meta_query'][0]['value'] ?? null);
        $this->assertCount(1, $books);

        WordPressStub::fake('get_posts', static fn (array $args): array => [
            (object) ['ID' => 1],
            (object) ['ID' => 2],
        ]);

        $this->assertSame(2, Book::query()->count());
    }

    public function testFindOrFailThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to find model');

        Book::query()->findOrFail(999);
    }

    public function testModelEventsFireInOrder(): void
    {
        Book::flushEventListeners();

        $events = [];

        Book::saving(static function () use (&$events): void {
            $events[] = 'saving';
        });

        Book::creating(static function () use (&$events): void {
            $events[] = 'creating';
        });

        Book::created(static function () use (&$events): void {
            $events[] = 'created';
        });

        Book::saved(static function () use (&$events): void {
            $events[] = 'saved';
        });

        Book::updating(static function () use (&$events): void {
            $events[] = 'updating';
        });

        Book::updated(static function () use (&$events): void {
            $events[] = 'updated';
        });

        WordPressStub::fake('wp_insert_post', static fn (): int => 200);

        WordPressStub::fake('get_post', static fn (): object => (object) [
            'ID' => 200,
            'post_title' => 'Eventful',
            'post_status' => 'publish',
        ]);

        $metaStore = [];

        WordPressStub::fake('update_post_meta', function (int $postId, string $key, mixed $value) use (&$metaStore): void {
            $metaStore[$postId][$key] = $value;
        });

        WordPressStub::fake('get_post_meta', function (int $postId, string $key) use (&$metaStore): mixed {
            return $metaStore[$postId][$key] ?? '';
        });

        Book::create(['title' => 'Eventful']);

        $book = (new Book())->newFromPost((object) ['ID' => 200, 'post_title' => 'Eventful', 'post_status' => 'publish']);

        $book->fill(['book_isbn' => '123'])->save();

        $this->assertSame(
            ['saving', 'creating', 'created', 'saved', 'saving', 'updating', 'updated', 'saved'],
            $events
        );
    }

    public function testSoftDeletesAndRestoreFlow(): void
    {
        $metaStore = [
            9 => [
                'book_isbn' => '9780000000010',
            ],
        ];

        WordPressStub::fake('get_post_meta', function (int $postId, string $key) use (&$metaStore): mixed {
            return $metaStore[$postId][$key] ?? '';
        });

        WordPressStub::fake('update_post_meta', function (int $postId, string $key, mixed $value) use (&$metaStore): void {
            $metaStore[$postId][$key] = $value;
        });

        WordPressStub::fake('delete_post_meta', function (int $postId, string $key) use (&$metaStore): void {
            unset($metaStore[$postId][$key]);
        });

        $post = (object) [
            'ID' => 9,
            'post_title' => 'Soft Book',
            'post_status' => 'publish',
            'post_name' => 'soft-book',
        ];

        $book = (new Book())->newFromPost($post);

        $book->delete();

        $this->assertTrue($book->trashed());
        $this->assertArrayHasKey('deleted_at', $metaStore[9]);

        $captured = [];

        WordPressStub::fake('get_posts', function (array $args) use (&$captured): array {
            $captured[] = $args;

            return [];
        });

        Book::query()->all();
        Book::query()->onlyTrashed()->all();

        $this->assertSame('NOT EXISTS', $captured[0]['meta_query'][0]['compare'] ?? null);
        $this->assertSame('EXISTS', $captured[1]['meta_query'][0]['compare'] ?? null);

        $book->restore();
        $this->assertFalse($book->trashed());
        $this->assertArrayNotHasKey('deleted_at', $metaStore[9]);
    }
}

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
