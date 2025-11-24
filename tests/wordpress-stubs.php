<?php

declare(strict_types=1);

use Luminate\Tests\Support\WordPressStub;

if (!function_exists('register_post_type')) {
    function register_post_type(string $postType, array $args = []): void
    {
        WordPressStub::call('register_post_type', [$postType, $args]);
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hookName, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        WordPressStub::call('add_action', [$hookName, $callback, $priority, $acceptedArgs]);
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hookName, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        WordPressStub::call('add_filter', [$hookName, $callback, $priority, $acceptedArgs]);
    }
}

if (!function_exists('wp_insert_post')) {
    function wp_insert_post(array $postarr, bool $wp_error = false): mixed
    {
        return WordPressStub::call('wp_insert_post', [$postarr, $wp_error]);
    }
}

if (!function_exists('get_post')) {
    function get_post(int $postId): ?object
    {
        return WordPressStub::call('get_post', [$postId]);
    }
}

if (!function_exists('get_posts')) {
    /**
     * @return array<int, object>
     */
    function get_posts(array $args = []): array
    {
        return WordPressStub::call('get_posts', [$args]);
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta(int $postId, string $key, bool $single = false): mixed
    {
        return WordPressStub::call('get_post_meta', [$postId, $key, $single]);
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta(int $postId, string $key, mixed $value): void
    {
        WordPressStub::call('update_post_meta', [$postId, $key, $value]);
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta(int $postId, string $key): void
    {
        WordPressStub::call('delete_post_meta', [$postId, $key]);
    }
}

if (!function_exists('wp_delete_post')) {
    function wp_delete_post(int $postId, bool $forceDelete = false): void
    {
        WordPressStub::call('wp_delete_post', [$postId, $forceDelete]);
    }
}
