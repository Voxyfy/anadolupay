<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay;

use Illuminate\Support\ServiceProvider;

/**
 * AnadoluPay Service Provider
 *
 * This service provider is responsible for bootstrapping the AnadoluPay package
 * within a Laravel application. It handles configuration publishing, service
 * container bindings, and package initialization.
 *
 * @package Voxyfy\AnadoluPay
 */
class AnadoluPayServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * This method is called during the registration phase of the service provider.
     * It binds the AnadoluPay class to the service container as a singleton,
     * ensuring only one instance exists throughout the application lifecycle.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/anadolupay.php',
            'anadolupay'
        );

        $this->app->singleton(AnadoluPay::class, function ($app) {
            return new AnadoluPay($app['config']['anadolupay']);
        });

        $this->app->alias(AnadoluPay::class, 'anadolupay');
    }

    /**
     * Bootstrap any application services.
     *
     * This method is called after all service providers have been registered.
     * It publishes the configuration file to the application's config directory,
     * allowing users to customize the package settings.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/anadolupay.php' => config_path('anadolupay.php'),
        ], 'anadolupay-config');
    }

    /**
     * Get the services provided by the provider.
     *
     * Returns an array of service container bindings that this provider
     * registers. This is used for deferred provider loading.
     *
     * @return array<int, string> Array of service identifiers
     */
    public function provides(): array
    {
        return [
            AnadoluPay::class,
            'anadolupay',
        ];
    }
}
