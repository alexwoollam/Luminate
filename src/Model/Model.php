<?php

declare(strict_types=1);

namespace Luminate\Model;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;
use Luminate\Contracts\Model as ModelContract;
use Luminate\Contracts\WordPress as WordPressContract;
use Luminate\Model\Column\CallbackColumn;
use Luminate\Model\Column\Column;
use Luminate\Model\Query\Builder;
use Luminate\Support\WordPress as WordPressAdapter;
use RuntimeException;

abstract class Model implements ModelContract
{
    public const TYPE_STRING = 'string';
    public const TYPE_INT = 'int';
    public const TYPE_BOOL = 'bool';
    public const TYPE_DATETIME = 'datetime';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    /**
     * @var array<string, mixed>
     */
    protected array $attributes = [];

    /**
     * @var array<string, mixed>
     */
    protected array $original = [];

    /**
     * @var array<string, mixed>
     */
    protected array $relations = [];

    /**
     * @var array<string, array<string, array<int, callable>>>
     */
    protected static array $eventListeners = [];

    protected bool $exists = false;

    protected ?object $postObject = null;

    protected WordPressContract $wordpress;

    public function __construct(array $attributes = [], ?WordPressContract $wordpress = null)
    {
        $this->wordpress = $wordpress ?? new WordPressAdapter();

        if ($attributes !== []) {
            $this->fill($attributes);
        }
    }

    public static function flushEventListeners(): void
    {
        unset(static::$eventListeners[static::class]);
    }

    public function setWordPress(WordPressContract $wordpress): void
    {
        $this->wordpress = $wordpress;
    }

    final public function wordpress(): WordPressContract
    {
        return $this->wordpress;
    }

    public static function creating(callable $callback): void
    {
        static::registerModelEvent('creating', $callback);
    }

    public static function created(callable $callback): void
    {
        static::registerModelEvent('created', $callback);
    }

    public static function saving(callable $callback): void
    {
        static::registerModelEvent('saving', $callback);
    }

    public static function saved(callable $callback): void
    {
        static::registerModelEvent('saved', $callback);
    }

    public static function updating(callable $callback): void
    {
        static::registerModelEvent('updating', $callback);
    }

    public static function updated(callable $callback): void
    {
        static::registerModelEvent('updated', $callback);
    }

    public static function deleting(callable $callback): void
    {
        static::registerModelEvent('deleting', $callback);
    }

    public static function deleted(callable $callback): void
    {
        static::registerModelEvent('deleted', $callback);
    }

    public static function restoring(callable $callback): void
    {
        static::registerModelEvent('restoring', $callback);
    }

    public static function restored(callable $callback): void
    {
        static::registerModelEvent('restored', $callback);
    }

    abstract public function key(): string;

    final public function register(): void
    {
        $this->wordpress->registerPostType($this->key(), $this->definition());

        $this->registerColumns();
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return static|array<int, static>|null
     */
    final public function find(int|string $mode = 'all', array $args = []): static|array|null
    {
        $builder = $this->newQuery();

        if (is_int($mode)) {
            return $builder->find($mode);
        }

        if (is_string($mode)) {
            if ($mode === 'first') {
                return $builder->first($args);
            }

            if ($mode === 'all' || $mode === '') {
                return $builder->all($args);
            }
        }

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        throw new InvalidArgumentException(sprintf('Unsupported find mode [%s] for model [%s].', (string) $mode, static::class));
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public static function query(): Builder
    {
        return (new static())->newQuery();
    }

    protected function newQuery(): Builder
    {
        return new Builder($this->key(), $this);
    }

    protected static function registerModelEvent(string $event, callable $callback): void
    {
        static::$eventListeners[static::class][$event][] = $callback;
    }

    protected function fireModelEvent(string $event): bool
    {
        $listeners = static::$eventListeners[static::class][$event] ?? [];

        foreach ($listeners as $listener) {
            if ($listener($this) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if (!$this->isFillableAttribute($key)) {
                continue;
            }

            $this->setAttribute($key, $value);
        }

        return $this;
    }

    public function setAttribute(string $key, mixed $value): static
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function __get(string $key): mixed
    {
        if ($this->relationLoaded($key)) {
            return $this->relations[$key];
        }

        if (method_exists($this, $key)) {
            return $this->relations[$key] = $this->{$key}();
        }

        return $this->getAttribute($key);
    }

    public function id(): ?int
    {
        $id = $this->getAttribute('id');

        return $id !== null ? (int) $id : null;
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public function post(): ?object
    {
        return $this->postObject;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function create(array $attributes): static
    {
        $model = new static();
        $model->fill($attributes);
        $model->insert();

        return $model;
    }

    final public function fresh(): ?static
    {
        $id = $this->id();

        if ($id === null) {
            return null;
        }

        $post = $this->wordpress->getPost($id);

        if (!$post) {
            return null;
        }

        return $this->newFromPost($post);
    }

    final public function refresh(): static
    {
        $fresh = $this->fresh();

        if ($fresh === null) {
            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
            throw new RuntimeException(sprintf('Unable to refresh model [%s].', static::class));
            // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        $this->setRawAttributes($fresh->toArray(), true);
        $this->postObject = $fresh->post();

        return $this;
    }

    public function save(): void
    {
        if (!$this->exists) {
            $this->insert();

            return;
        }

        $postId = $this->id();

        if ($postId === null) {
            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
            throw new RuntimeException(sprintf('Cannot save model [%s] without an ID.', static::class));
            // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        if ($this->fireModelEvent('saving') === false) {
            return;
        }

        if ($this->fireModelEvent('updating') === false) {
            return;
        }

        if ($this->usesTimestamps()) {
            $this->touchTimestamps();
        }

        $postAttributes = $this->gatherPostAttributesForSaving();
        $metaAttributes = $this->gatherFillableAttributesForSaving();

        if ($postAttributes === [] && $metaAttributes === []) {
            return;
        }

        if ($postAttributes !== []) {
            $this->performUpdate($postId, $postAttributes);
        }

        if ($metaAttributes !== []) {
            $this->saveMeta($postId, $metaAttributes);
        }

        $this->syncOriginalAttributes();
        $this->refreshFromSource();
        $this->fireModelEvent('updated');
        $this->fireModelEvent('saved');
    }

    protected function insert(): void
    {
        if ($this->fireModelEvent('saving') === false) {
            return;
        }

        if ($this->fireModelEvent('creating') === false) {
            return;
        }

        $postId = $this->performInsert();

        $this->setAttribute($this->getKeyName(), $postId);
        $this->exists = true;

        if ($this->usesTimestamps()) {
            $this->touchTimestamps();
        }

        $dirty = $this->gatherFillableAttributesForSaving();

        if ($dirty !== []) {
            $this->saveMeta($postId, $dirty);
        }

        $this->syncOriginalAttributes();
        $this->refreshFromSource();
        $this->fireModelEvent('created');
        $this->fireModelEvent('saved');
    }

    protected function performInsert(): int
    {
        $result = $this->wordpress->wpInsertPost($this->buildPostData(), true);

        if (is_int($result)) {
            return $result;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- exception message only
        $message = sprintf('Unable to insert model [%s].', static::class);

        if (is_object($result) && method_exists($result, 'get_error_message')) {
            $message .= ' ' . $result->get_error_message();
        }

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        throw new RuntimeException($message);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    protected function performUpdate(int $postId, array $attributes): void
    {
        $payload = array_merge(['ID' => $postId], $attributes);
        $result = $this->wordpress->wpUpdatePost($payload, true);

        if (is_int($result)) {
            return;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- exception message only
        $message = sprintf('Unable to update model [%s].', static::class);

        if (is_object($result) && method_exists($result, 'get_error_message')) {
            $message .= ' ' . $result->get_error_message();
        }

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        throw new RuntimeException($message);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    protected function performDelete(): void
    {
        $postId = $this->id();

        if ($postId === null) {
            return;
        }

        $this->wordpress->wpDeletePost($postId, true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPostData(): array
    {
        $data = [
            'post_type' => $this->key(),
        ];

        foreach ($this->postAttributeMap() as $attribute => $postKey) {
            $value = $this->getAttribute($attribute);

            if ($attribute === 'status' && $value === null) {
                $value = $this->defaultPostStatus();
            }

            if ($value === null) {
                continue;
            }

            $data[$postKey] = $this->preparePostAttributeValue($attribute, $value);
        }

        return $data;
    }

    protected function defaultPostStatus(): string
    {
        return 'publish';
    }

    public function delete(): void
    {
        if (!$this->exists) {
            return;
        }

        if ($this->fireModelEvent('deleting') === false) {
            return;
        }

        if ($this->usesSoftDeletes()) {
            $deletedAtColumn = $this->getDeletedAtColumn();
            $timestamp = $this->freshTimestamp();
            $this->setAttribute($deletedAtColumn, $timestamp);
            $this->saveMeta((int) $this->id(), [$deletedAtColumn => $timestamp]);
            $this->syncOriginalAttributes();
            $this->fireModelEvent('deleted');

            return;
        }

        $this->performDelete();
        $this->exists = false;
        $this->fireModelEvent('deleted');
    }

    public function forceDelete(): void
    {
        if (!$this->exists) {
            return;
        }

        if ($this->fireModelEvent('deleting') === false) {
            return;
        }

        if ($this->usesSoftDeletes()) {
            $deletedAtColumn = $this->getDeletedAtColumn();
            $this->setAttribute($deletedAtColumn, null);
            $this->saveMeta((int) $this->id(), [$deletedAtColumn => null]);
        }

        $this->performDelete();
        $this->exists = false;
        $this->fireModelEvent('deleted');
    }

    public function restore(): void
    {
        if (!$this->usesSoftDeletes() || !$this->trashed()) {
            return;
        }

        if ($this->fireModelEvent('restoring') === false) {
            return;
        }

        $deletedAtColumn = $this->getDeletedAtColumn();
        $this->setAttribute($deletedAtColumn, null);
        $this->saveMeta((int) $this->id(), [$deletedAtColumn => null]);
        $this->syncOriginalAttributes();
        $this->fireModelEvent('restored');
    }

    public function trashed(): bool
    {
        if (!$this->usesSoftDeletes()) {
            return false;
        }

        return $this->getAttribute($this->getDeletedAtColumn()) !== null;
    }


    /**
     * @return array<string, mixed>
     */
    protected function gatherFillableAttributesForSaving(): array
    {
        $attributes = [];

        foreach ($this->metaAttributes() as $field => $type) {
            if (!array_key_exists($field, $this->attributes)) {
                continue;
            }

            if (!$this->isAttributeDirty($field)) {
                continue;
            }

            $attributes[$field] = $this->attributes[$field];
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    protected function gatherPostAttributesForSaving(): array
    {
        $attributes = [];

        foreach ($this->postAttributeMap() as $attribute => $postKey) {
            if (!$this->isAttributeDirty($attribute)) {
                continue;
            }

            $value = $this->getAttribute($attribute);

            if ($attribute === 'status' && $value === null) {
                $value = $this->defaultPostStatus();
            }

            if ($value === null) {
                continue;
            }

            $attributes[$postKey] = $this->preparePostAttributeValue($attribute, $value);
        }

        return $attributes;
    }

    /**
     * @param array<int, string>|string $relations
     */
    public function load(array|string $relations): static
    {
        foreach ((array) $relations as $relation) {
            if (!method_exists($this, $relation)) {
                // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
                throw new RuntimeException(sprintf('Relation [%s] is not defined on [%s].', $relation, static::class));
                // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
            }

            $this->setRelation($relation, $this->{$relation}());
        }

        return $this;
    }

    public function relationLoaded(string $relation): bool
    {
        return array_key_exists($relation, $this->relations);
    }

    public function getRelation(string $relation): mixed
    {
        return $this->relations[$relation] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRelations(): array
    {
        return $this->relations;
    }

    protected function setRelation(string $relation, mixed $value): void
    {
        $this->relations[$relation] = $value;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    final public function saveMeta(int $postId, array $attributes): void
    {
        foreach ($this->metaAttributes() as $field => $type) {
            if (!array_key_exists($field, $attributes)) {
                continue;
            }

            $rawValue = $attributes[$field];

            if ($rawValue === null) {
                $this->wordpress->deletePostMeta($postId, $field);

                continue;
            }

            $value = $this->prepareValueForStorage($rawValue, $type);

            $this->wordpress->updatePostMeta($postId, $field, $value);
        }
    }

    /**
     * @return array<string, mixed>
     */
    final public function definition(): array
    {
        return array_replace_recursive(
            [
                'labels' => $this->labels(),
                'supports' => $this->supports(),
            ],
            $this->adminOptions(),
            $this->options()
        );
    }

    /**
     * @return array<string, string>
     */
    abstract protected function labels(): array;

    /**
     * @return list<string>
     */
    protected function supports(): array
    {
        return ['title', 'editor'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function options(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function admin(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function adminOptions(): array
    {
        $admin = $this->admin();
        $options = [];

        if (($admin['admin_dash'] ?? false) === true) {
            $options = [
                'show_ui' => true,
                'show_in_menu' => true,
                'show_in_admin_bar' => true,
                'show_in_rest' => true,
            ];
        }

        foreach (['show_ui', 'show_in_menu', 'show_in_admin_bar', 'show_in_nav_menus', 'show_in_rest', 'menu_position', 'menu_icon'] as $key) {
            if (array_key_exists($key, $admin)) {
                $options[$key] = $admin[$key];
            }
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    protected function postAttributeMap(): array
    {
        return [
            'title' => 'post_title',
            'slug' => 'post_name',
            'status' => 'post_status',
            'content' => 'post_content',
            'excerpt' => 'post_excerpt',
        ];
    }

    protected function preparePostAttributeValue(string $attribute, mixed $value): mixed
    {
        return match ($attribute) {
            'title', 'slug', 'content', 'excerpt', 'status' => (string) $value,
            default => $value,
        };
    }

    /**
     * @return array<string, string>
     */
    protected function fillable(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    protected function metaAttributes(): array
    {
        $attributes = $this->fillable();

        if ($this->usesTimestamps()) {
            if ($created = $this->getCreatedAtColumn()) {
                $attributes[$created] = self::TYPE_DATETIME;
            }

            if ($updated = $this->getUpdatedAtColumn()) {
                $attributes[$updated] = self::TYPE_DATETIME;
            }
        }

        if ($this->usesSoftDeletes()) {
            $attributes[$this->getDeletedAtColumn()] = self::TYPE_DATETIME;
        }

        return $attributes;
    }

    /**
     * @return list<Column>
     */
    protected function columns(): array
    {
        return [];
    }

    public function softDeletesEnabled(): bool
    {
        return $this->usesSoftDeletes();
    }

    public function deletedAtColumn(): string
    {
        return $this->getDeletedAtColumn();
    }

    protected function usesSoftDeletes(): bool
    {
        return false;
    }

    protected function getDeletedAtColumn(): string
    {
        return 'deleted_at';
    }

    protected function baseFillableAttributes(): array
    {
        $base = array_merge(['id'], array_keys($this->postAttributeMap()));

        if ($this->usesTimestamps()) {
            $base = [
                ...$base,
                ...array_filter([$this->getCreatedAtColumn(), $this->getUpdatedAtColumn()]),
            ];
        }

        if ($this->usesSoftDeletes()) {
            $base[] = $this->getDeletedAtColumn();
        }

        return array_values(array_unique($base));
    }

    protected function isFillableAttribute(string $key): bool
    {
        return in_array($key, [...$this->baseFillableAttributes(), ...array_keys($this->fillable())], true);
    }

    protected function isAttributeDirty(string $key): bool
    {
        if (!$this->isFillableAttribute($key)) {
            return false;
        }

        if (!$this->exists) {
            return array_key_exists($key, $this->attributes);
        }

        if (!array_key_exists($key, $this->original)) {
            return array_key_exists($key, $this->attributes);
        }

        $current = $this->attributes[$key] ?? null;
        $original = $this->original[$key];

        return $current != $original;
    }

    protected function newInstance(array $attributes = [], bool $exists = false, ?object $post = null): static
    {
        $model = new static([], $this->wordpress);
        $model->setRawAttributes($attributes, $exists);
        $model->postObject = $post;

        return $model;
    }

    final public function newFromPost(object $post): static
    {
        return $this->newInstance($this->attributesFromPost($post), true, $post);
    }

    protected function attributesFromPost(object $post): array
    {
        $attributes = [
            'id' => property_exists($post, 'ID') ? (int) $post->ID : null,
            'title' => $post->post_title ?? null,
            'slug' => $post->post_name ?? null,
            'status' => $post->post_status ?? null,
        ];

        $postId = isset($post->ID) ? (int) $post->ID : 0;

        foreach ($this->metaAttributes() as $field => $type) {
            $raw = $this->retrieveFillableValue($field, $postId);
            $attributes[$field] = $this->castValueFromStorage($raw, $type);
        }

        return $attributes;
    }

    protected function setRawAttributes(array $attributes, bool $exists): void
    {
        $this->attributes = $attributes;
        $this->exists = $exists;
        $this->resetRelations();
        $this->syncOriginalAttributes();
    }

    protected function syncOriginalAttributes(): void
    {
        $this->original = $this->attributes;
    }

    protected function resetRelations(): void
    {
        $this->relations = [];
    }

    protected function refreshFromSource(): void
    {
        $fresh = $this->fresh();

        if ($fresh === null) {
            return;
        }

        $this->setRawAttributes($fresh->toArray(), true);
        $this->postObject = $fresh->post();
    }

    protected function getKeyName(): string
    {
        return 'id';
    }

    protected function usesTimestamps(): bool
    {
        return true;
    }

    protected function getCreatedAtColumn(): ?string
    {
        return self::CREATED_AT;
    }

    protected function getUpdatedAtColumn(): ?string
    {
        return self::UPDATED_AT;
    }

    protected function freshTimestamp(): DateTimeInterface
    {
        return new DateTimeImmutable('now');
    }

    protected function touchTimestamps(): void
    {
        if (!$this->usesTimestamps()) {
            return;
        }

        $now = $this->freshTimestamp();

        $createdColumn = $this->getCreatedAtColumn();
        $updatedColumn = $this->getUpdatedAtColumn();

        if (!$this->exists && $createdColumn) {
            $this->setAttribute($createdColumn, $now);
        }

        if ($updatedColumn) {
            $this->setAttribute($updatedColumn, $now);
        }
    }

    private function registerColumns(): void
    {
        $columns = [
            ...$this->columns(),
            ...$this->fillableColumns(),
        ];

        if ($columns === []) {
            return;
        }

        $columnMap = [];
        $columnLabels = [];

        foreach ($columns as $column) {
            $columnMap[$column->key()] = $column;
            $columnLabels[$column->key()] = $this->sanitizeColumnLabel($column->label());
        }

        $postTypeKey = $this->key();

        try {
            $this->wordpress->addFilter(
                sprintf('manage_%s_posts_columns', $postTypeKey),
                /**
                 * @param array<string, string> $existing
                 *
                 * @return array<string, string>
                 */
                static function (array $existing) use ($columnLabels): array {
                    foreach ($columnLabels as $key => $label) {
                        $existing[$key] = $label;
                    }

                    return $existing;
                }
            );

            $this->wordpress->addAction(
                sprintf('manage_%s_posts_custom_column', $postTypeKey),
                static function (string $columnName, int $postId) use ($columnMap): void {
                    if (!isset($columnMap[$columnName])) {
                        return;
                    }

                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer is responsible for escaping
                    echo $columnMap[$columnName]->render($postId);
                },
                10,
                2
            );
        } catch (RuntimeException) {
            return;
        }
    }

    /**
     * @return list<Column>
     */
    private function fillableColumns(): array
    {
        $columns = [];

        foreach ($this->fillable() as $field => $type) {
            $columns[] = new CallbackColumn(
                $field,
                $this->columnLabel($field),
                function (int $postId) use ($field, $type): string {
                    return $this->renderFillableColumn($field, $type, $postId);
                }
            );
        }

        return $columns;
    }

    protected function columnLabel(string $field): string
    {
        $label = ucwords(str_replace('_', ' ', $field));
        $domain = $this->textDomain();

        if ($domain !== null && function_exists('__')) {
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText,WordPress.WP.I18n.DomainNotLiteral -- dynamic labels derived from field names
            return __($label, $domain);
        }

        return $label;
    }

    protected function textDomain(): ?string
    {
        return null;
    }

    protected function retrieveFillableValue(string $field, int $postId): mixed
    {
        return $this->wordpress->getPostMeta($postId, $field, true);
    }

    private function castValueFromStorage(mixed $value, string $type): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }

        return match ($type) {
            self::TYPE_BOOL => in_array($value, ['1', 1, true, 'true', 'yes', 'on'], true),
            self::TYPE_INT => (int) $value,
            self::TYPE_DATETIME => $this->castDateTimeFromStorage($value),
            default => (string) $value,
        };
    }

    private function prepareValueForStorage(mixed $value, string $type): mixed
    {
        return match ($type) {
            self::TYPE_BOOL => $this->castBooleanForStorage($value),
            self::TYPE_INT => (int) $value,
            self::TYPE_DATETIME => $this->castDateTimeForStorage($value),
            default => (string) $value,
        };
    }

    private function castBooleanForStorage(mixed $value): string
    {
        $truthy = ['1', 1, true, 'true', 'yes', 'on'];

        return in_array($value, $truthy, true) ? '1' : '0';
    }

    private function castDateTimeForStorage(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_numeric($value)) {
            return (string) (int) $value;
        }

        return (string) $value;
    }

    private function castDateTimeFromStorage(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_numeric($value)) {
            $datetime = DateTimeImmutable::createFromFormat('U', (string) (int) $value);

            return $datetime ?: null;
        }

        try {
            return new DateTimeImmutable((string) $value);
        } catch (Exception) {
            return null;
        }
    }

    private function renderFillableColumn(string $field, string $type, int $postId): string
    {
        $value = $this->castValueFromStorage(
            $this->retrieveFillableValue($field, $postId),
            $type
        );

        if ($value === null || $value === '') {
            return $this->escapeColumnValue('—');
        }

        $rendered = match ($type) {
            self::TYPE_BOOL => $this->formatBooleanValue($value),
            self::TYPE_INT => (string) $value,
            self::TYPE_DATETIME => $this->formatDateTimeValue($value),
            default => (string) $value,
        };

        return $this->escapeColumnValue((string) $rendered);
    }

    private function sanitizeColumnLabel(string $label): string
    {
        if (function_exists('esc_html')) {
            return esc_html($label);
        }

        return htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    }

    private function escapeColumnValue(string $value): string
    {
        if (function_exists('esc_html')) {
            return esc_html($value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function formatBooleanValue(mixed $value): string
    {
        $truthy = ['1', 1, true, 'true', 'yes', 'on'];

        $label = in_array($value, $truthy, true) ? 'Yes' : 'No';
        $domain = $this->textDomain();

        if ($domain !== null && function_exists('__')) {
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText,WordPress.WP.I18n.DomainNotLiteral -- boolean labels are dynamic
            return __($label, $domain);
        }

        return $label;
    }

    private function formatDateTimeValue(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            $timestamp = $value->getTimestamp();
        } elseif (is_numeric($value)) {
            $timestamp = (int) $value;
        } else {
            $timestamp = strtotime((string) $value) ?: null;
        }

        if ($timestamp === null) {
            return (string) $value;
        }

        if (function_exists('wp_date')) {
            return wp_date('Y-m-d H:i', $timestamp);
        }

        return gmdate('Y-m-d H:i', $timestamp);
    }

    /**
     * @template T of Model
     *
     * @param class-string<T> $related
     *
     * @return T|null
     */
    protected function belongsTo(string $related, string $foreignKey, ?string $ownerKey = null): ?Model
    {
        $foreignId = $this->getAttribute($foreignKey);

        if ($foreignId === null) {
            return null;
        }

        $instance = $this->newRelatedInstance($related);
        $ownerKey ??= $instance->getKeyName();

        if ($ownerKey === $instance->getKeyName()) {
            return $instance->find((int) $foreignId);
        }

        return $instance->find('first', [
            'meta_query' => [
                [
                    'key' => $ownerKey,
                    'value' => $foreignId,
                ],
            ],
        ]);
    }

    /**
     * @template T of Model
     *
     * @param class-string<T> $related
     *
     * @return array<int, T>
     */
    protected function hasMany(string $related, string $foreignKey, ?string $localKey = null): array
    {
        $localKey ??= $this->getKeyName();
        $localValue = $this->getAttribute($localKey);

        if ($localValue === null) {
            return [];
        }

        $instance = $this->newRelatedInstance($related);

        /** @var array<int, T> $results */
        $results = $instance->find('all', [
            'meta_query' => [
                [
                    'key' => $foreignKey,
                    'value' => (string) $localValue,
                ],
            ],
        ]);

        return $results;
    }

    /**
     * @template T of Model
     *
     * @param class-string<T> $related
     *
     * @return T|null
     */
    protected function hasOne(string $related, string $foreignKey, ?string $localKey = null): ?Model
    {
        $results = $this->hasMany($related, $foreignKey, $localKey);

        return $results[0] ?? null;
    }

    /**
     * @template T of Model
     *
     * @param class-string<T> $related
     *
     * @return T
     */
    protected function newRelatedInstance(string $related): Model
    {
        $instance = new $related();

        if (!$instance instanceof self) {
            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
            throw new RuntimeException(sprintf('Related class [%s] must extend %s.', $related, self::class));
            // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        return $instance;
    }
}
