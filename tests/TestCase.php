<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Voxyfy\AnadoluPay\AnadoluPayServiceProvider;

/**
 * Temel Test Sınıfı
 *
 * Tüm paket testleri için temel oluşturur ve Laravel test ortamını
 * AnadoluPay service provider ile yapılandırır.
 */
class TestCase extends Orchestra
{
    /**
     * Paket için service provider'ları döndürür.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AnadoluPayServiceProvider::class,
        ];
    }

    /**
     * Ortam ayarlarını tanımlar.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
    }
}
