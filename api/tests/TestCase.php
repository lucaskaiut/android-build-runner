<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Guarantees tests always run against the dedicated test database.
     * docker compose injects DB_* variables into $_SERVER, which takes
     * precedence over phpunit.xml, so the value is pinned here.
     */
    public function createApplication()
    {
        $database = getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? 'build_platform_test');

        if (str_contains((string) $database, 'test')) {
            $_SERVER['DB_DATABASE'] = $database;
        }

        $_SERVER['DB_CONNECTION'] = 'mysql';

        return parent::createApplication();
    }
}
