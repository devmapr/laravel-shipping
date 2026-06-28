<?php

declare(strict_types=1);

namespace Planx\Shipping\Tests;

use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base test case for the Shipping package.
 *
 * Uses Orchestra Testbench to bootstrap a full Laravel application
 * with all facades (DB, Log, Config, etc.) available.
 */
abstract class TestCase extends Orchestra
{
    /**
     * Service providers to load during tests.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            \Planx\Shipping\Providers\ShippingServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // Use SQLite in-memory database for tests
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Load package config
        $app['config']->set('shipping.default', 'snappbox');
        $app['config']->set('shipping.drivers.snappbox', [
            'class' => \Planx\Shipping\Drivers\SnappBoxDriver::class,
            'config' => [
                'active' => true,
                'api_base_url' => 'https://test.snappbox.com',
                'webhook_token' => 'test-token',
            ],
        ]);
    }
}
