<?php

declare(strict_types=1);

namespace Luminate\Tests\Model;

use DateTimeImmutable;
use DateTimeInterface;
use Luminate\Model\Model;
use Luminate\Tests\Support\FakeWordPress;
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

    public function testRegisterUsesInjectedWordPressService(): void
    {
        $fake = new FakeWordPress();
        $book = new Book([], $fake);

        $book->register();

        $this->assertSame('book', $fake->registeredPostTypes[0]['post_type'] ?? null);
        $this->assertNotEmpty($fake->filters);
        $this->assertNotEmpty($fake->actions);
    }

    public function testRegisterIncludesAdminDashboardOptions(): void
    {
        $fake = new FakeWordPress();

        $model = new class ([], $fake) extends Model {
            public function key(): string
            {
                return 'admin_item';
            }

            protected function labels(): array
            {
                return [
                    'name' => 'Admin Items',
                    'singular_name' => 'Admin Item',
                ];
            }

            protected function admin(): array
            {
                return ['admin_dash' => true];
            }
        };

        $model->register();

        $args = $fake->registeredPostTypes[0]['args'] ?? [];

        $this->assertTrue($args['show_ui'] ?? false);
        $this->assertTrue($args['show_in_menu'] ?? false);
        $this->assertTrue($args['show_in_admin_bar'] ?? false);
        $this->assertTrue($args['show_in_rest'] ?? false);
    }

    public function testRegisterHonorsAdminDashboardOverrides(): void
    {
        $fake = new FakeWordPress();

        $model = new class ([], $fake) extends Model {
            public function key(): string
            {
                return 'admin_override';
            }

            protected function labels(): array
            {
                return [
                    'name' => 'Admin Overrides',
                    'singular_name' => 'Admin Override',
                ];
            }

            protected function admin(): array
            {
                return [
                    'admin_dash' => true,
                    'show_in_rest' => false,
                    'menu_icon' => 'dashicons-admin-generic',
                ];
            }
        };

        $model->register();

        $args = $fake->registeredPostTypes[0]['args'] ?? [];

        $this->assertTrue($args['show_ui'] ?? false);
        $this->assertTrue($args['show_in_menu'] ?? false);
        $this->assertTrue($args['show_in_admin_bar'] ?? false);
        $this->assertFalse($args['show_in_rest'] ?? true);
        $this->assertSame('dashicons-admin-generic', $args['menu_icon'] ?? null);
    }

    public function testSavePersistsNewModelViaSaveMethod(): void
    {
        $metaStore = [];
        $capturedPost = [];

        WordPressStub::fake('wp_insert_post', function (array $data, bool $error) use (&$capturedPost): int {
            $capturedPost = $data;

            return 150;
        });

        WordPressStub::fake('update_post_meta', function (int $postId, string $key, mixed $value) use (&$metaStore): void {
            $metaStore[$postId][$key] = $value;
        });

        WordPressStub::fake('get_post_meta', function (int $postId, string $key) use (&$metaStore): mixed {
            return $metaStore[$postId][$key] ?? '';
        });

        WordPressStub::fake('get_post', static fn (int $postId): object => (object) [
            'ID' => $postId,
            'post_title' => 'Saved Title',
            'post_status' => 'draft',
            'post_name' => 'saved-title',
        ]);

        $book = new Book();
        $book->fill([
            'title' => 'My Saved Book',
            'slug' => 'my-saved-book',
            'status' => 'draft',
            'book_isbn' => '9780000000011',
            'is_featured' => false,
        ]);

        $book->save();

        $this->assertSame(150, $book->id());
        $this->assertSame('book', $capturedPost['post_type'] ?? null);
        $this->assertSame('My Saved Book', $capturedPost['post_title'] ?? null);
        $this->assertSame('draft', $capturedPost['post_status'] ?? null);
        $this->assertSame('9780000000011', $metaStore[150]['book_isbn'] ?? null);
        $this->assertSame('0', $metaStore[150]['is_featured'] ?? null);
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

    public function testSaveUpdatesPostAttributesAndMeta(): void
    {
        $metaStore = [
            77 => [
                'book_isbn' => '9780000000005',
                'is_featured' => '0',
            ],
        ];

        WordPressStub::fake('get_post_meta', function (int $postId, string $key) use (&$metaStore): mixed {
            return $metaStore[$postId][$key] ?? '';
        });

        $posts = [
            77 => (object) [
                'ID' => 77,
                'post_title' => 'Original Book',
                'post_status' => 'publish',
                'post_name' => 'original-book',
            ],
        ];

        WordPressStub::fake('get_post', static fn (int $postId): ?object => $posts[$postId] ?? null);

        $updatedPost = [];

        WordPressStub::fake('wp_update_post', function (array $payload) use (&$updatedPost, &$posts): int {
            $updatedPost = $payload;
            $id = $payload['ID'];

            if (!isset($posts[$id])) {
                return $id;
            }

            foreach ($payload as $key => $value) {
                if ($key === 'ID') {
                    continue;
                }

                $posts[$id]->{$key} = $value;
            }

            return $id;
        });

        WordPressStub::fake('update_post_meta', function (int $postId, string $key, mixed $value) use (&$metaStore): void {
            $metaStore[$postId][$key] = $value;
        });

        $book = (new Book())->newFromPost($posts[77]);

        $book->fill([
            'title' => 'Updated Title',
            'book_isbn' => '9780000000006',
        ]);

        $book->save();

        $this->assertSame('Updated Title', $updatedPost['post_title'] ?? null);
        $this->assertSame('9780000000006', $metaStore[77]['book_isbn'] ?? null);
        $this->assertArrayHasKey('updated_at', $metaStore[77]);
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

    public function testFindHonorsSoftDeleteFlags(): void
    {
        $post = (object) [
            'ID' => 54,
            'post_title' => 'Trashed Book',
            'post_status' => 'publish',
            'post_name' => 'trashed-book',
        ];

        WordPressStub::fake('get_post', static fn (int $postId): ?object => $postId === 54 ? $post : null);

        $metaStore = [
            54 => [
                'book_isbn' => '9780000000015',
                'deleted_at' => '2024-01-01T00:00:00+00:00',
            ],
        ];

        WordPressStub::fake('get_post_meta', function (int $postId, string $key) use (&$metaStore): mixed {
            return $metaStore[$postId][$key] ?? '';
        });

        $this->assertNull(Book::query()->find(54));

        $withTrashed = Book::query()->withTrashed()->find(54);
        $this->assertNotNull($withTrashed);
        $this->assertTrue($withTrashed->trashed());

        $onlyTrashed = Book::query()->onlyTrashed()->find(54);
        $this->assertNotNull($onlyTrashed);
        $this->assertTrue($onlyTrashed->trashed());

        $metaStore[54]['deleted_at'] = '';

        $this->assertNull(Book::query()->onlyTrashed()->find(54));
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

    public function testDateTimeAttributesAreCastedToImmutable(): void
    {
        $metaStore = [
            31 => [
                'book_isbn' => '9780000000020',
                'deleted_at' => '2024-05-01T09:00:00+00:00',
            ],
        ];

        WordPressStub::fake('get_post_meta', function (int $postId, string $key) use (&$metaStore): mixed {
            return $metaStore[$postId][$key] ?? '';
        });

        $post = (object) [
            'ID' => 31,
            'post_title' => 'Dated Book',
            'post_status' => 'publish',
            'post_name' => 'dated-book',
        ];

        $book = (new Book())->newFromPost($post);

        $this->assertInstanceOf(DateTimeImmutable::class, $book->deleted_at);
        $this->assertSame('2024-05-01T09:00:00+00:00', $book->deleted_at?->format(DateTimeInterface::ATOM));
    }

    public function testFillableColumnsEscapeOutput(): void
    {
        WordPressStub::fake('get_post_meta', static fn (int $postId, string $key): string => '<script>alert(1)</script>');

        $capturedRenderer = null;

        WordPressStub::fake('add_action', function (string $hookName, callable $callback, int $priority = 10, int $acceptedArgs = 1) use (&$capturedRenderer): void {
            if ($hookName === 'manage_book_posts_custom_column') {
                $capturedRenderer = $callback;
            }
        });

        $book = new Book();
        $book->register();

        $this->assertIsCallable($capturedRenderer);

        ob_start();
        $capturedRenderer('book_isbn', 5);
        $output = ob_get_clean();

        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', trim((string) $output));
    }
}
