<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay;

use Illuminate\Support\ServiceProvider;

/**
 * AnadoluPay Service Provider
 *
 * Bu service provider, AnadoluPay paketinin Laravel uygulaması içinde
 * başlatılmasından sorumludur. Yapılandırma yayınlama, service container
 * bağlamaları ve paket başlatma işlemlerini yönetir.
 */
class AnadoluPayServiceProvider extends ServiceProvider
{
    /**
     * Uygulama servislerini kaydet.
     *
     * Bu metot, service provider'ın kayıt aşamasında çağrılır.
     * AnadoluPay sınıfını service container'a singleton olarak bağlar,
     * böylece uygulama yaşam döngüsü boyunca tek bir instance bulunur.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/anadolupay.php',
            'anadolupay'
        );

        $this->app->singleton(AnadoluPay::class, function ($app) {
            return new AnadoluPay($app['config']['anadolupay']);
        });

        $this->app->alias(AnadoluPay::class, 'anadolupay');
    }

    /**
     * Uygulama servislerini başlat.
     *
     * Bu metot, tüm service provider'lar kaydedildikten sonra çağrılır.
     * Yapılandırma dosyasını uygulamanın config dizinine yayınlar,
     * böylece kullanıcılar paket ayarlarını özelleştirebilir.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/anadolupay.php' => config_path('anadolupay.php'),
        ], 'anadolupay-config');
    }

    /**
     * Provider tarafından sağlanan servisleri döndürür.
     *
     * Bu provider'ın kaydettiği service container bağlamalarının
     * bir dizisini döndürür. Ertelenmiş provider yüklemesi için kullanılır.
     *
     * @return array<int, string> Servis tanımlayıcıları dizisi
     */
    public function provides(): array
    {
        return [
            AnadoluPay::class,
            'anadolupay',
        ];
    }
}
