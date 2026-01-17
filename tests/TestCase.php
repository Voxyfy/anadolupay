<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Voxyfy\AnadoluPay\AnadoluPayServiceProvider;

/**
 * Base Test Case
 *
 * Provides the foundation for all package tests, configuring
 * the Laravel testing environment with the AnadoluPay service provider.
 *
 * @package Voxyfy\AnadoluPay\Tests
 */
class TestCase extends Orchestra
{
    /**
     * Get the service providers for the package.
     *
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AnadoluPayServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param \Illuminate\Foundation\Application $app
     *
     * @return void
     */
    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
    }
}
