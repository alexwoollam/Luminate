# Luminate

Laravel-inspired tooling for building WordPress custom post types with strict, SOLID PHP.

## Installation

```bash
composer require luminate/luminate
```

## Quick start

```php
<?php

declare(strict_types=1);

use Luminate\Luminate;
use Luminate\Model\Model;
use Luminate\Model\Concerns\SoftDeletes;

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
            'published_at' => self::TYPE_DATETIME,
            'is_featured' => self::TYPE_BOOL,
        ];
    }
}

Luminate::boot(new Book());

$firstBook = (new Book())->find('first');

if ($firstBook) {
    $firstBook->fill(['is_featured' => true])->save();
}

$featuredBooks = (new Book())->find('all', [
    'meta_query' => [
        [
            'key' => 'is_featured',
            'value' => '1',
        ],
    ],
]);
```

## Creating models

Use the static `create()` helper to insert a WordPress post alongside all of its fillable/meta attributes:

```php
$book = Book::create([
    'title' => 'My First Book',
    'status' => 'publish',
    'book_isbn' => '9780000000001',
    'is_featured' => true,
]);
```

## Custom columns

Override `fillable()` to declare the meta fields you want to expose in the admin table. Supported types are `string`, `int`, `bool`, and `datetime` (via the `Model::TYPE_*` constants). Luminate automatically creates and renders the columns for you using WordPress meta.

For bespoke behaviour you can still override `columns()` and return your own `Luminate\Model\Column\Column` instances alongside the fillable fields.

## Using the model elsewhere

Because everything is autoloaded via Composer's PSR-4 rules, you can instantiate your model from any file (theme, plugin, mu-plugin, etc.):

```php
<?php

declare(strict_types=1);

use MyPlugin\Models\Book;
use Luminate\Luminate;

require __DIR__ . '/vendor/autoload.php';

Luminate::boot(new Book());

$latest = (new Book())->find('first');
```

## Saving data

Hydrate a model, mutate the attributes, and call `save()` to persist the fillable fields:

```php
$book = (new Book())->find(42);

if ($book) {
    $book
        ->fill([
            'book_isbn' => '9780000000002',
            'published_at' => '2024-05-01',
            'is_featured' => true,
        ])
        ->save();
}
```

If you just need to sync raw data (for example, inside a `save_post` hook), you can still call `saveMeta($postId, $attributes)` directly:

```php
use DateTimeImmutable;

(new Book())->saveMeta($postId, [
    'book_isbn' => '9780000000002',
    'published_at' => new DateTimeImmutable('2024-05-01'),
    'is_featured' => true,
]);

```

Only keys declared in `fillable()` are stored; values are automatically cast according to their type definitions.

Need to create a brand-new record without using `Book::create()`? Instantiate your model, `fill()` it, and call `save()`—Luminate automatically detects that the model doesn't exist yet and issues `wp_insert_post` alongside the fillable meta writes:

```php
$book = (new Book())
    ->fill([
        'title' => 'My Second Book',
        'status' => 'draft',
        'book_isbn' => '9780000000002',
        'is_featured' => false,
    ]);

$book->save();
```

Datetime attributes declared as `TYPE_DATETIME` are hydrated into `DateTimeImmutable` instances, so comparisons stay consistent before and after you call `save()`/`refresh()`.

`created_at` and `updated_at` meta keys are automatically added to the base fillable attributes and touched every time `save()` runs (override `usesTimestamps()` or the column names if you need something different). Dirty tracking ensures only changed attributes hit the database.

## Relationships

Use the built-in helpers to model `belongsTo`, `hasMany`, or `hasOne` relationships via post meta:

```php
final class Author extends Model
{
    public function key(): string
    {
        return 'author';
    }

    protected function labels(): array
    {
        return ['name' => 'Authors', 'singular_name' => 'Author'];
    }

    protected function fillable(): array
    {
        return ['name' => self::TYPE_STRING];
    }

    public function books(): array
    {
        return $this->hasMany(Book::class, 'author_id');
    }
}

final class Book extends Model
{
    // ...

    protected function fillable(): array
    {
        return [
            'book_isbn' => self::TYPE_STRING,
            'author_id' => self::TYPE_INT,
        ];
    }

    public function author(): ?Author
    {
        return $this->belongsTo(Author::class, 'author_id');
    }
}

$book = (new Book())->find('first');
$author = $book?->author(); // lazy loads via __get
$booksForAuthor = $author?->books();

// Or eager load via the fluent query builder:
$bookWithAuthor = Book::query()->with('author')->first();

// Load relationships on an existing instance:
$book?->load(['author', 'reviews']);
```

## Soft deletes

Pull in `Luminate\Model\Concerns\SoftDeletes` to enable `deleted_at` support, `delete()`, `restore()`, `withTrashed()`, and `onlyTrashed()` query helpers:

```php
final class Book extends Model
{
    use SoftDeletes;
}

$book = Book::query()->findOrFail(42);
$book->delete();            // sets deleted_at meta
$book->restore();           // clears deleted_at
$allBooks = Book::query()->withTrashed()->all();
$trashed = Book::query()->onlyTrashed()->count();
```

The query builder adds a `NOT EXISTS` meta filter by default so soft-deleted entries stay hidden unless explicitly requested.

## Model events

Hook into lifecycle events (e.g., `creating`, `created`, `saving`, `saved`, `updating`, `updated`, `deleting`, `deleted`, `restoring`, `restored`) to keep side effects localized:

```php
Book::creating(static function (Book $book): void {
    $book->slug = sanitize_title($book->title);
});

Book::deleted(static function (Book $book): void {
    do_action('book_deleted', $book->id());
});
```

## Querying data

Instantiate a model and call `find()` for simple fetching:

- `find('first')` returns the first published model (or `null`).
- `find('all', $args)` returns an array of hydrated models (use standard `get_posts` args).
- `find(42)` returns the model with ID `42` if it belongs to the post type.

For more control, call `Book::query()` to obtain a builder with fluent constraints such as `whereStatus()`, `whereMeta()`, `orderBy()`, `limit()`, and eager-loading via `with()`:

```php
$book = Book::query()
    ->whereStatus('publish')
    ->whereMeta('is_featured', '1')
    ->orderBy('title', 'desc')
    ->limit(5)
    ->with(['author'])
    ->firstOrFail();
```

Define scopes on your model to encapsulate common constraints:

```php
use Luminate\Model\Query\Builder;

public function scopeFeatured(Builder $query): Builder
{
    return $query->whereMeta('is_featured', '1');
}

$count = Book::query()->featured()->count();
```

Additional helpers include `findOrFail()`, `firstOrFail()`, `count()`, and `where()` for passing raw `get_posts` arguments when necessary.

## WordPress integration

Every model depends on the `Luminate\Contracts\WordPress` service to talk to core APIs. The default adapter calls the real WordPress functions, but you can inject your own implementation (for example, to mock network calls or proxy through another layer):

```php
use Acme\Infrastructure\CustomWordPress;

$book = new Book([], new CustomWordPress());
$book->register();
```

When booting via `Luminate::boot()`, the kernel automatically injects its shared WordPress service so hooks and registrars inherit the same adapter.

## Admin dashboard integration

Expose your custom post type in the WordPress admin menus by overriding `admin()` and enabling the `admin_dash` flag:

```php
protected function admin(): array
{
    return [
        'admin_dash' => true,
        'menu_icon' => 'dashicons-book',
        'menu_position' => 20,
    ];
}
```

`admin_dash` maps to `show_ui`, `show_in_menu`, `show_in_admin_bar`, and `show_in_rest`. You can override any of those keys directly in the same array.

## Customizing post attributes

By default `title`, `slug`, `status`, `content`, and `excerpt` map directly to WordPress' built-in post columns. Override `postAttributeMap()` (and optionally `preparePostAttributeValue()`) to expose extra keys and control their serialization:

```php
protected function postAttributeMap(): array
{
    return [
        ...parent::postAttributeMap(),
        'menu_order' => 'menu_order',
        'author' => 'post_author',
    ];
}

protected function preparePostAttributeValue(string $attribute, mixed $value): mixed
{
    if ($attribute === 'author') {
        return (int) $value;
    }

    return parent::preparePostAttributeValue($attribute, $value);
}
```

These attributes participate in dirty tracking automatically, so subsequent `save()` calls trigger `wp_update_post` whenever you change them.

## Localising admin columns

Every fillable attribute automatically becomes an admin column. Override `textDomain()` when you want those column labels—even the generated "Yes"/"No" boolean text—to flow through WordPress' translation functions:

```php
protected function textDomain(): ?string
{
    return 'my-plugin';
}
```

Column labels and values are escaped via `esc_html()` (with a graceful fallback outside of WordPress) before rendering, so user-supplied meta never leaks raw HTML into wp-admin.

## Development

Run the Composer checks to validate metadata and autoloading:

```bash
composer check
```

Execute the automated test suite:

```bash
composer test
```

Adhere to PSR-12 by running the automated linter:

```bash
composer lint
```

This project ships with PHP_CodeSniffer (PSR-12 baseline) plus the WordPress coding standards dependencies so you can gradually enable additional WordPress-specific sniffs as needed (see `phpcs.xml`).
