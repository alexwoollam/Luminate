<?php

declare(strict_types=1);

namespace Luminate\Support;

use Luminate\Contracts\WordPress as WordPressContract;
use RuntimeException;

final class WordPress implements WordPressContract
{
    public function registerPostType(string $postType, array $args = []): void
    {
        if (!function_exists('register_post_type')) {
            throw new RuntimeException('register_post_type is not available.');
        }

        register_post_type($postType, $args);
    }

    public function addAction(string $hookName, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        if (!function_exists('add_action')) {
            throw new RuntimeException('add_action is not available.');
        }

        add_action($hookName, $callback, $priority, $acceptedArgs);
    }

    public function addFilter(string $hookName, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        if (!function_exists('add_filter')) {
            throw new RuntimeException('add_filter is not available.');
        }

        add_filter($hookName, $callback, $priority, $acceptedArgs);
    }

    public function wpInsertPost(array $postarr, bool $wpError = false): mixed
    {
        if (!function_exists('wp_insert_post')) {
            throw new RuntimeException('wp_insert_post is not available.');
        }

        return wp_insert_post($postarr, $wpError);
    }

    public function wpUpdatePost(array $postarr, bool $wpError = false): mixed
    {
        if (!function_exists('wp_update_post')) {
            throw new RuntimeException('wp_update_post is not available.');
        }

        return wp_update_post($postarr, $wpError);
    }

    public function wpDeletePost(int $postId, bool $forceDelete = false): void
    {
        if (!function_exists('wp_delete_post')) {
            throw new RuntimeException('wp_delete_post is not available.');
        }

        wp_delete_post($postId, $forceDelete);
    }

    public function getPost(int $postId): ?object
    {
        if (!function_exists('get_post')) {
            throw new RuntimeException('get_post is not available.');
        }

        return get_post($postId);
    }

    public function getPosts(array $args = []): array
    {
        if (!function_exists('get_posts')) {
            throw new RuntimeException('get_posts is not available.');
        }

        return get_posts($args);
    }

    public function getPostMeta(int $postId, string $key, bool $single = true): mixed
    {
        if (!function_exists('get_post_meta')) {
            throw new RuntimeException('get_post_meta is not available.');
        }

        return get_post_meta($postId, $key, $single);
    }

    public function updatePostMeta(int $postId, string $key, mixed $value): void
    {
        if (!function_exists('update_post_meta')) {
            throw new RuntimeException('update_post_meta is not available.');
        }

        update_post_meta($postId, $key, $value);
    }

    public function deletePostMeta(int $postId, string $key): void
    {
        if (!function_exists('delete_post_meta')) {
            throw new RuntimeException('delete_post_meta is not available.');
        }

        delete_post_meta($postId, $key);
    }
}
