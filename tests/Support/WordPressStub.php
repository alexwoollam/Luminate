<?php

declare(strict_types=1);

namespace Luminate\Tests\Support;

use RuntimeException;

final class WordPressStub
{
    /**
     * @var array<string, callable>
     */
    private static array $handlers = [];

    public static function fake(string $function, callable $callback): void
    {
        self::$handlers[$function] = $callback;
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public static function call(string $function, array $arguments = []): mixed
    {
        if (!array_key_exists($function, self::$handlers)) {
            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
            throw new RuntimeException(sprintf('No stub has been registered for [%s].', $function));
            // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        return (self::$handlers[$function])(...$arguments);
    }

    public static function reset(): void
    {
        self::$handlers = [];
    }
}
