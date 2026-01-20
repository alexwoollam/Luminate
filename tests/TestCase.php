<?php

declare(strict_types=1);

namespace Luminate\Tests;

use Luminate\Tests\Support\WordPressStub;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WordPressStub::reset();
        WordPressStub::fake('register_post_type', static fn (): null => null);
        WordPressStub::fake('add_action', static fn (): null => null);
        WordPressStub::fake('add_filter', static fn (): null => null);
        WordPressStub::fake('delete_post_meta', static fn (): null => null);
        WordPressStub::fake('update_post_meta', static fn (): null => null);
        WordPressStub::fake('get_post_meta', static fn (): string => '');
        WordPressStub::fake('get_post', static fn (): ?object => null);
        WordPressStub::fake('get_posts', static fn (): array => []);
        WordPressStub::fake('wp_insert_post', static fn (): int => 0);
        WordPressStub::fake('wp_update_post', static fn (): int => 0);
        WordPressStub::fake('wp_delete_post', static fn (): null => null);

        foreach (['Luminate\\Tests\\Model\\Book', 'Luminate\\Tests\\Model\\Author'] as $modelClass) {
            if (class_exists($modelClass, false)) {
                $modelClass::flushEventListeners();
            }
        }
    }
}
