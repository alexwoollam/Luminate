<?php

declare(strict_types=1);

namespace Luminate\Tests\Support;

use Luminate\Contracts\WordPress as WordPressContract;

final class FakeWordPress implements WordPressContract
{
    public array $registeredPostTypes = [];

    public array $actions = [];

    public array $filters = [];

    public array $posts = [];

    public array $meta = [];

    public int $nextInsertId = 1;

    public function registerPostType(string $postType, array $args = []): void
    {
        $this->registeredPostTypes[] = ['post_type' => $postType, 'args' => $args];
    }

    public function addAction(string $hookName, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $this->actions[] = compact('hookName', 'callback', 'priority', 'acceptedArgs');
    }

    public function addFilter(string $hookName, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $this->filters[] = compact('hookName', 'callback', 'priority', 'acceptedArgs');
    }

    public function wpInsertPost(array $postarr, bool $wpError = false): mixed
    {
        $id = $this->nextInsertId++;
        $this->posts[$id] = (object) array_merge(['ID' => $id], $postarr);

        return $id;
    }

    public function wpUpdatePost(array $postarr, bool $wpError = false): mixed
    {
        $id = $postarr['ID'] ?? 0;

        if ($id === 0) {
            return 0;
        }

        $this->posts[$id] = (object) array_merge((array) ($this->posts[$id] ?? []), $postarr);

        return $id;
    }

    public function wpDeletePost(int $postId, bool $forceDelete = false): void
    {
        unset($this->posts[$postId]);
    }

    public function getPost(int $postId): ?object
    {
        return $this->posts[$postId] ?? null;
    }

    public function getPosts(array $args = []): array
    {
        return array_values($this->posts);
    }

    public function getPostMeta(int $postId, string $key, bool $single = true): mixed
    {
        return $this->meta[$postId][$key] ?? '';
    }

    public function updatePostMeta(int $postId, string $key, mixed $value): void
    {
        $this->meta[$postId][$key] = $value;
    }

    public function deletePostMeta(int $postId, string $key): void
    {
        unset($this->meta[$postId][$key]);
    }
}
