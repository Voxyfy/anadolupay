<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay;

use Illuminate\Support\ServiceProvider;

/**
 * AnadoluPay Service Provider
 *
 * Paket yapılandırmasını ve service container bağlamalarını yönetir.
 */
class AnadoluPayServiceProvider extends ServiceProvider
{
    /**
     * Uygulama servislerini kaydet.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/anadolupay.php',
            'anadolupay'
        );

        $this->app->singleton('anadolupay', fn () => new AnadoluPay);
        $this->app->alias('anadolupay', AnadoluPay::class);
    }

    /**
     * Uygulama servislerini başlat.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/anadolupay.php');

        $this->publishes([
            __DIR__.'/../config/anadolupay.php' => config_path('anadolupay.php'),
        ], 'anadolupay-config');
    }
}
