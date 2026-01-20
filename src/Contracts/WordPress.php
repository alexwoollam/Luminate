<?php

declare(strict_types=1);

namespace Luminate\Contracts;

interface WordPress
{
    public function registerPostType(string $postType, array $args = []): void;

    public function addAction(string $hookName, callable $callback, int $priority = 10, int $acceptedArgs = 1): void;

    public function addFilter(string $hookName, callable $callback, int $priority = 10, int $acceptedArgs = 1): void;

    public function wpInsertPost(array $postarr, bool $wpError = false): mixed;

    public function wpUpdatePost(array $postarr, bool $wpError = false): mixed;

    public function wpDeletePost(int $postId, bool $forceDelete = false): void;

    public function getPost(int $postId): ?object;

    /**
     * @return array<int, object>
     */
    public function getPosts(array $args = []): array;

    public function getPostMeta(int $postId, string $key, bool $single = true): mixed;

    public function updatePostMeta(int $postId, string $key, mixed $value): void;

    public function deletePostMeta(int $postId, string $key): void;
}
