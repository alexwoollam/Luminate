<?php

declare(strict_types=1);

namespace Luminate\Model\Query;

use Luminate\Contracts\WordPress as WordPressContract;
use Luminate\Model\Model;
use RuntimeException;

final class Builder
{
    /**
     * @var list<string>
     */
    private array $with = [];

    /**
     * @var array<string, mixed>
     */
    private array $args = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $metaQuery = [];

    private bool $includeTrashed = false;

    private bool $onlyTrashed = false;

    private WordPressContract $wordpress;

    public function __construct(
        private readonly string $postType,
        private readonly Model $prototype
    ) {
        $this->wordpress = $prototype->wordpress();
    }

    /**
     * @param array<int, string>|string $relations
     */
    public function with(array|string $relations): self
    {
        foreach ((array) $relations as $relation) {
            $this->with[] = $relation;
        }

        return $this;
    }

    public function withTrashed(): self
    {
        $this->includeTrashed = true;
        $this->onlyTrashed = false;

        return $this;
    }

    public function onlyTrashed(): self
    {
        $this->includeTrashed = false;
        $this->onlyTrashed = true;

        return $this;
    }

    public function withoutTrashed(): self
    {
        $this->includeTrashed = false;
        $this->onlyTrashed = false;

        return $this;
    }

    public function whereStatus(string $status): self
    {
        $this->args['post_status'] = $status;

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->args['orderby'] = $column;
        $this->args['order'] = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->args['numberposts'] = $limit;

        return $this;
    }

    public function whereMeta(string $key, mixed $value, string $compare = '='): self
    {
        $query = [
            'key' => $key,
            'compare' => $compare,
        ];

        if (!in_array(strtoupper($compare), ['EXISTS', 'NOT EXISTS'], true)) {
            $query['value'] = $value;
        }

        $this->metaQuery[] = $query;

        return $this;
    }

    public function where(array $args): self
    {
        $this->args = array_merge($this->args, $args);

        return $this;
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<int, Model>
     */
    public function all(array $args = []): array
    {
        return $this->runQuery($args);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function first(array $args = []): ?Model
    {
        $models = $this->runQuery(array_merge(['numberposts' => 1], $args));

        return $models[0] ?? null;
    }

    public function firstOrFail(array $args = []): Model
    {
        $model = $this->first($args);

        if ($model === null) {
            throw new RuntimeException('Unable to locate the first matching model.');
        }

        return $model;
    }

    public function find(int $id): ?Model
    {
        $post = $this->wordpress->getPost($id);

        if (!$post || (isset($post->post_type) && $post->post_type !== $this->postType)) {
            return null;
        }

        $model = $this->prototype->newFromPost($post);

        if ($this->prototype->softDeletesEnabled()) {
            if ($this->onlyTrashed && !$model->trashed()) {
                return null;
            }

            if (!$this->includeTrashed && !$this->onlyTrashed && $model->trashed()) {
                return null;
            }
        }

        if ($this->with !== []) {
            $model->load($this->with);
        }

        return $model;
    }

    public function findOrFail(int $id): Model
    {
        $model = $this->find($id);

        if ($model === null) {
            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
            throw new RuntimeException(sprintf('Unable to find model [%s] with ID [%d].', $this->postType, $id));
            // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        return $model;
    }

    public function count(array $args = []): int
    {
        return count($this->runQuery(array_merge(['fields' => 'ids', 'numberposts' => -1], $args)));
    }

    public function __call(string $method, array $arguments): self
    {
        $scope = 'scope' . ucfirst($method);

        if (method_exists($this->prototype, $scope)) {
            $result = $this->prototype->{$scope}($this, ...$arguments);

            if (!$result instanceof self) {
                // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
                throw new RuntimeException(sprintf('Scope [%s] must return a Builder instance.', $scope));
                // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
            }

            return $result;
        }

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        throw new RuntimeException(sprintf('Call to undefined builder method [%s].', $method));
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<int, Model>
     */
    private function runQuery(array $args): array
    {
        $args = $this->buildQueryArgs($args);

        /** @var array<int, object> $posts */
        $posts = $this->wordpress->getPosts($args);

        $models = array_map(
            fn (object $post): Model => $this->prototype->newFromPost($post),
            $posts
        );

        return $this->eagerLoad($models);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function buildQueryArgs(array $overrides = []): array
    {
        $args = array_merge(
            [
                'post_type' => $this->postType,
                'post_status' => 'publish',
            ],
            $this->args,
            $overrides
        );

        $metaQuery = [];

        if (isset($args['meta_query']) && is_array($args['meta_query'])) {
            $metaQuery = array_values($args['meta_query']);
        }

        $metaQuery = array_merge($metaQuery, $this->metaQuery);

        if ($this->prototype->softDeletesEnabled()) {
            $deletedColumn = $this->prototype->deletedAtColumn();

            if ($this->onlyTrashed) {
                $metaQuery[] = [
                    'key' => $deletedColumn,
                    'compare' => 'EXISTS',
                ];
            } elseif (!$this->includeTrashed) {
                $metaQuery[] = [
                    'key' => $deletedColumn,
                    'compare' => 'NOT EXISTS',
                ];
            }
        }

        if ($metaQuery !== []) {
            $args['meta_query'] = array_values(array_map(
                static function (array $query): array {
                    if (!isset($query['compare'])) {
                        $query['compare'] = '=';
                    }

                    return $query;
                },
                $metaQuery
            ));
        } else {
            unset($args['meta_query']);
        }

        return $args;
    }

    /**
     * @param array<int, Model> $models
     *
     * @return array<int, Model>
     */
    private function eagerLoad(array $models): array
    {
        if ($this->with === []) {
            return $models;
        }

        foreach ($models as $model) {
            $model->load($this->with);
        }

        return $models;
    }
}
